<?php

/**
 * PeacePay Admin Panel
 * 
 * Web-based admin interface for managing PeacePay operations.
 * Split into separate files in production:
 * - app/Http/Controllers/Admin/*.php
 * - resources/views/admin/*.blade.php
 */

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\PeaceLink;
use App\Models\Transaction;
use App\Models\Dispute;
use App\Models\CashoutRequest;
use App\Models\KycRequest;
use App\Models\PlatformFee;
use App\Services\DisputeService;
use App\Services\CashoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

// ============================================================================
// Admin Dashboard Controller
// ============================================================================

class DashboardController extends Controller
{
    public function index()
    {
        $today = today();
        $thisMonth = now()->startOfMonth();

        $stats = [
            'users' => [
                'total' => User::count(),
                'new_today' => User::whereDate('created_at', $today)->count(),
                'new_this_month' => User::where('created_at', '>=', $thisMonth)->count(),
                'verified' => User::whereNotNull('phone_verified_at')->count(),
            ],
            'peacelinks' => [
                'total' => PeaceLink::count(),
                'active' => PeaceLink::whereIn('status', ['pending', 'funded', 'dsp_assigned', 'in_transit'])->count(),
                'completed_today' => PeaceLink::whereDate('released_at', $today)->count(),
                'volume_today' => PeaceLink::whereDate('released_at', $today)->sum('item_amount'),
            ],
            'disputes' => [
                'pending' => Dispute::where('status', 'pending')->count(),
                'under_review' => Dispute::where('status', 'under_review')->count(),
            ],
            'cashouts' => [
                'pending' => CashoutRequest::where('status', 'pending')->count(),
                'pending_amount' => CashoutRequest::where('status', 'pending')->sum('amount'),
            ],
            'kyc' => [
                'pending' => KycRequest::where('status', 'pending')->count(),
            ],
            'revenue' => [
                'today' => PlatformFee::whereDate('created_at', $today)->sum('amount'),
                'this_month' => PlatformFee::where('created_at', '>=', $thisMonth)->sum('amount'),
            ],
        ];

        // Recent activities
        $recentPeacelinks = PeaceLink::with(['buyer:id,name', 'merchant:id,name'])
            ->latest()
            ->take(10)
            ->get();

        $recentDisputes = Dispute::with(['opener:id,name', 'peaceLink:id,reference'])
            ->latest()
            ->take(5)
            ->get();

        $recentCashouts = CashoutRequest::with(['user:id,name'])
            ->where('status', 'pending')
            ->latest()
            ->take(5)
            ->get();

        return view('admin.dashboard', compact('stats', 'recentPeacelinks', 'recentDisputes', 'recentCashouts'));
    }
}


// ============================================================================
// User Management Controller
// ============================================================================

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::with('wallet');

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by KYC level
        if ($request->filled('kyc_level')) {
            $query->where('kyc_level', $request->kyc_level);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('is_active', $request->status === 'active');
        }

        // Filter by DSP
        if ($request->filled('is_dsp')) {
            $query->where('is_dsp', $request->is_dsp === 'yes');
        }

        $users = $query->latest()->paginate(20);

        return view('admin.users.index', compact('users'));
    }

    public function show(User $user)
    {
        $user->load(['wallet', 'transactions' => fn($q) => $q->latest()->take(10)]);

        $stats = [
            'peacelinks_as_buyer' => PeaceLink::where('buyer_id', $user->id)->count(),
            'peacelinks_as_merchant' => PeaceLink::where('merchant_id', $user->id)->count(),
            'total_spent' => PeaceLink::where('buyer_id', $user->id)->where('status', 'released')->sum('total_amount'),
            'total_earned' => PeaceLink::where('merchant_id', $user->id)->where('status', 'released')->sum('item_amount'),
            'disputes_opened' => Dispute::where('opened_by', $user->id)->count(),
            'disputes_won' => Dispute::where('opened_by', $user->id)->where('resolution', 'buyer')->count(),
        ];

        return view('admin.users.show', compact('user', 'stats'));
    }

    public function toggleActive(User $user)
    {
        $user->update(['is_active' => !$user->is_active]);

        return back()->with('success', $user->is_active ? 'تم تفعيل الحساب' : 'تم تعليق الحساب');
    }

    public function toggleDsp(User $user)
    {
        $user->update(['is_dsp' => !$user->is_dsp]);

        return back()->with('success', $user->is_dsp ? 'تم تعيين المستخدم كـ DSP' : 'تم إلغاء تعيين DSP');
    }

    public function adjustBalance(Request $request, User $user)
    {
        $request->validate([
            'amount' => 'required|numeric',
            'type' => 'required|in:credit,debit',
            'reason' => 'required|string|min:10',
        ]);

        $wallet = $user->wallet;
        $amount = abs($request->amount);

        if ($request->type === 'debit' && $wallet->balance < $amount) {
            return back()->with('error', 'رصيد غير كافي');
        }

        DB::transaction(function () use ($wallet, $user, $amount, $request) {
            if ($request->type === 'credit') {
                $wallet->increment('balance', $amount);
            } else {
                $wallet->decrement('balance', $amount);
            }

            // Record transaction
            Transaction::create([
                'uuid' => \Illuminate\Support\Str::uuid(),
                'wallet_id' => $wallet->id,
                'user_id' => $user->id,
                'reference' => 'ADJ-' . strtoupper(\Illuminate\Support\Str::random(8)),
                'type' => 'admin_adjustment',
                'direction' => $request->type,
                'amount' => $amount,
                'fee' => 0,
                'balance_before' => $request->type === 'credit' ? $wallet->balance - $amount : $wallet->balance + $amount,
                'balance_after' => $wallet->balance,
                'status' => 'completed',
                'description' => "تعديل إداري: {$request->reason}",
                'metadata' => ['admin_id' => auth()->id(), 'reason' => $request->reason],
            ]);
        });

        return back()->with('success', 'تم تعديل الرصيد بنجاح');
    }
}


// ============================================================================
// Dispute Management Controller
// ============================================================================

class DisputeController extends Controller
{
    protected DisputeService $disputeService;

    public function __construct(DisputeService $disputeService)
    {
        $this->disputeService = $disputeService;
    }

    public function index(Request $request)
    {
        $query = Dispute::with(['opener:id,name', 'respondent:id,name', 'peaceLink:id,reference,item_amount']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Default: show pending first
        $query->orderByRaw("FIELD(status, 'pending', 'under_review', 'resolved') ASC")
              ->latest();

        $disputes = $query->paginate(20);

        return view('admin.disputes.index', compact('disputes'));
    }

    public function show(Dispute $dispute)
    {
        $dispute->load([
            'opener',
            'respondent',
            'peaceLink.buyer',
            'peaceLink.merchant',
            'messages',
        ]);

        return view('admin.disputes.show', compact('dispute'));
    }

    public function markUnderReview(Dispute $dispute)
    {
        if ($dispute->status !== 'pending') {
            return back()->with('error', 'لا يمكن تغيير حالة هذا النزاع');
        }

        $dispute->update(['status' => 'under_review']);

        return back()->with('success', 'تم تحديث حالة النزاع إلى "قيد المراجعة"');
    }

    public function resolve(Request $request, Dispute $dispute)
    {
        $request->validate([
            'resolution' => 'required|in:buyer,merchant,split',
            'notes' => 'required|string|min:10',
            'refund_percentage' => 'required_if:resolution,split|nullable|numeric|min:0|max:100',
        ]);

        if (!in_array($dispute->status, ['pending', 'under_review'])) {
            return back()->with('error', 'لا يمكن حل هذا النزاع');
        }

        $this->disputeService->resolveDispute(
            $dispute,
            $request->resolution,
            $request->notes,
            $request->refund_percentage ?? null,
            auth()->id()
        );

        return redirect()->route('admin.disputes.index')
            ->with('success', 'تم حل النزاع بنجاح');
    }
}


// ============================================================================
// Cashout Management Controller
// ============================================================================

class CashoutController extends Controller
{
    protected CashoutService $cashoutService;

    public function __construct(CashoutService $cashoutService)
    {
        $this->cashoutService = $cashoutService;
    }

    public function index(Request $request)
    {
        $query = CashoutRequest::with(['user:id,name,phone']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by method
        if ($request->filled('method')) {
            $query->where('method', $request->method);
        }

        // Default: show pending first
        $query->orderByRaw("FIELD(status, 'pending', 'processing', 'completed', 'failed') ASC")
              ->latest();

        $cashouts = $query->paginate(20);

        $pendingTotal = CashoutRequest::where('status', 'pending')->sum('amount');

        return view('admin.cashouts.index', compact('cashouts', 'pendingTotal'));
    }

    public function show(CashoutRequest $cashout)
    {
        $cashout->load(['user.wallet', 'transactions']);

        return view('admin.cashouts.show', compact('cashout'));
    }

    public function process(CashoutRequest $cashout)
    {
        if ($cashout->status !== 'pending') {
            return back()->with('error', 'لا يمكن معالجة هذا الطلب');
        }

        $this->cashoutService->processCashout($cashout);

        return back()->with('success', 'تم تحديث الحالة إلى "قيد المعالجة"');
    }

    public function complete(Request $request, CashoutRequest $cashout)
    {
        $request->validate([
            'gateway_reference' => 'nullable|string',
        ]);

        if ($cashout->status !== 'processing') {
            return back()->with('error', 'يجب أن يكون الطلب "قيد المعالجة" أولاً');
        }

        $this->cashoutService->completeCashout($cashout, $request->gateway_reference);

        return back()->with('success', 'تم إكمال طلب السحب');
    }

    public function fail(Request $request, CashoutRequest $cashout)
    {
        $request->validate([
            'reason' => 'required|string|min:10',
        ]);

        if (!in_array($cashout->status, ['pending', 'processing'])) {
            return back()->with('error', 'لا يمكن رفض هذا الطلب');
        }

        $this->cashoutService->failCashout($cashout, $request->reason);

        return back()->with('success', 'تم رفض طلب السحب وإرجاع المبلغ');
    }

    public function bulkProcess(Request $request)
    {
        $request->validate([
            'cashout_ids' => 'required|array',
            'cashout_ids.*' => 'exists:cashout_requests,id',
        ]);

        $processed = 0;
        foreach ($request->cashout_ids as $id) {
            $cashout = CashoutRequest::find($id);
            if ($cashout && $cashout->status === 'pending') {
                $this->cashoutService->processCashout($cashout);
                $processed++;
            }
        }

        return back()->with('success', "تم معالجة {$processed} طلب سحب");
    }
}


// ============================================================================
// KYC Management Controller
// ============================================================================

class KycController extends Controller
{
    public function index(Request $request)
    {
        $query = KycRequest::with(['user:id,name,phone']);

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by target level
        if ($request->filled('target_level')) {
            $query->where('target_level', $request->target_level);
        }

        $query->orderByRaw("FIELD(status, 'pending', 'under_review', 'approved', 'rejected') ASC")
              ->latest();

        $requests = $query->paginate(20);

        return view('admin.kyc.index', compact('requests'));
    }

    public function show(KycRequest $kycRequest)
    {
        $kycRequest->load(['user.wallet']);

        return view('admin.kyc.show', compact('kycRequest'));
    }

    public function approve(KycRequest $kycRequest)
    {
        if (!in_array($kycRequest->status, ['pending', 'under_review'])) {
            return back()->with('error', 'لا يمكن الموافقة على هذا الطلب');
        }

        DB::transaction(function () use ($kycRequest) {
            $kycRequest->update([
                'status' => 'approved',
                'reviewed_by' => auth()->id(),
                'reviewed_at' => now(),
            ]);

            $kycRequest->user->update([
                'kyc_level' => $kycRequest->target_level,
            ]);
        });

        // Fire event for notification
        event(new \App\Events\KycApproved($kycRequest->user, $kycRequest->target_level));

        return back()->with('success', 'تمت الموافقة على طلب الترقية');
    }

    public function reject(Request $request, KycRequest $kycRequest)
    {
        $request->validate([
            'reason' => 'required|string|min:10',
        ]);

        if (!in_array($kycRequest->status, ['pending', 'under_review'])) {
            return back()->with('error', 'لا يمكن رفض هذا الطلب');
        }

        $kycRequest->update([
            'status' => 'rejected',
            'rejection_reason' => $request->reason,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        // Fire event for notification
        event(new \App\Events\KycRejected($kycRequest->user, $request->reason));

        return back()->with('success', 'تم رفض طلب الترقية');
    }
}


// ============================================================================
// Reports Controller
// ============================================================================

class ReportController extends Controller
{
    public function transactions(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));

        $stats = Transaction::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->selectRaw('
                type,
                direction,
                COUNT(*) as count,
                SUM(amount) as total_amount,
                SUM(fee) as total_fees
            ')
            ->groupBy('type', 'direction')
            ->get();

        $daily = Transaction::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->selectRaw('
                DATE(created_at) as date,
                SUM(CASE WHEN direction = "credit" THEN amount ELSE 0 END) as credits,
                SUM(CASE WHEN direction = "debit" THEN amount ELSE 0 END) as debits,
                COUNT(*) as count
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        return view('admin.reports.transactions', compact('stats', 'daily', 'startDate', 'endDate'));
    }

    public function peacelinks(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));

        $byStatus = PeaceLink::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->selectRaw('status, COUNT(*) as count, SUM(item_amount) as total_amount')
            ->groupBy('status')
            ->get();

        $daily = PeaceLink::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->selectRaw('
                DATE(created_at) as date,
                COUNT(*) as created,
                SUM(CASE WHEN status = "released" THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = "cancelled" THEN 1 ELSE 0 END) as cancelled
            ')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $avgCompletionTime = PeaceLink::whereBetween('released_at', [$startDate, $endDate . ' 23:59:59'])
            ->whereNotNull('released_at')
            ->selectRaw('AVG(TIMESTAMPDIFF(HOUR, created_at, released_at)) as avg_hours')
            ->first();

        return view('admin.reports.peacelinks', compact('byStatus', 'daily', 'avgCompletionTime', 'startDate', 'endDate'));
    }

    public function revenue(Request $request)
    {
        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));

        $byType = PlatformFee::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->selectRaw('type, SUM(amount) as total')
            ->groupBy('type')
            ->get();

        $daily = PlatformFee::whereBetween('created_at', [$startDate, $endDate . ' 23:59:59'])
            ->selectRaw('DATE(created_at) as date, SUM(amount) as total')
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        $totalRevenue = $byType->sum('total');

        return view('admin.reports.revenue', compact('byType', 'daily', 'totalRevenue', 'startDate', 'endDate'));
    }

    public function export(Request $request)
    {
        $type = $request->get('type', 'transactions');
        $startDate = $request->get('start_date', now()->startOfMonth()->format('Y-m-d'));
        $endDate = $request->get('end_date', now()->format('Y-m-d'));

        // Generate CSV export
        $filename = "{$type}_{$startDate}_{$endDate}.csv";
        
        // Implementation would generate actual CSV file
        // For now, return placeholder response
        return response()->json(['message' => 'Export functionality to be implemented']);
    }
}


// ============================================================================
// Admin Routes (Add to routes/web.php)
// ============================================================================

/*
Route::prefix('admin')->middleware(['auth', 'admin'])->name('admin.')->group(function () {
    
    // Dashboard
    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    
    // Users
    Route::resource('users', UserController::class)->only(['index', 'show']);
    Route::post('users/{user}/toggle-active', [UserController::class, 'toggleActive'])->name('users.toggle-active');
    Route::post('users/{user}/toggle-dsp', [UserController::class, 'toggleDsp'])->name('users.toggle-dsp');
    Route::post('users/{user}/adjust-balance', [UserController::class, 'adjustBalance'])->name('users.adjust-balance');
    
    // Disputes
    Route::resource('disputes', DisputeController::class)->only(['index', 'show']);
    Route::post('disputes/{dispute}/review', [DisputeController::class, 'markUnderReview'])->name('disputes.review');
    Route::post('disputes/{dispute}/resolve', [DisputeController::class, 'resolve'])->name('disputes.resolve');
    
    // Cashouts
    Route::resource('cashouts', CashoutController::class)->only(['index', 'show']);
    Route::post('cashouts/{cashout}/process', [CashoutController::class, 'process'])->name('cashouts.process');
    Route::post('cashouts/{cashout}/complete', [CashoutController::class, 'complete'])->name('cashouts.complete');
    Route::post('cashouts/{cashout}/fail', [CashoutController::class, 'fail'])->name('cashouts.fail');
    Route::post('cashouts/bulk-process', [CashoutController::class, 'bulkProcess'])->name('cashouts.bulk-process');
    
    // KYC
    Route::get('kyc', [KycController::class, 'index'])->name('kyc.index');
    Route::get('kyc/{kycRequest}', [KycController::class, 'show'])->name('kyc.show');
    Route::post('kyc/{kycRequest}/approve', [KycController::class, 'approve'])->name('kyc.approve');
    Route::post('kyc/{kycRequest}/reject', [KycController::class, 'reject'])->name('kyc.reject');
    
    // Reports
    Route::prefix('reports')->name('reports.')->group(function () {
        Route::get('transactions', [ReportController::class, 'transactions'])->name('transactions');
        Route::get('peacelinks', [ReportController::class, 'peacelinks'])->name('peacelinks');
        Route::get('revenue', [ReportController::class, 'revenue'])->name('revenue');
        Route::get('export', [ReportController::class, 'export'])->name('export');
    });
});
*/


// ============================================================================
// Blade Layout Template (resources/views/admin/layouts/app.blade.php)
// ============================================================================

/*
<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title') - PeacePay Admin</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Cairo', sans-serif; }
    </style>
</head>
<body class="bg-gray-100">
    <div class="flex h-screen">
        <!-- Sidebar -->
        <aside class="w-64 bg-indigo-900 text-white">
            <div class="p-4">
                <h1 class="text-2xl font-bold">PeacePay</h1>
                <p class="text-indigo-300 text-sm">لوحة التحكم</p>
            </div>
            <nav class="mt-8">
                <a href="{{ route('admin.dashboard') }}" class="flex items-center px-4 py-3 hover:bg-indigo-800 {{ request()->routeIs('admin.dashboard') ? 'bg-indigo-800' : '' }}">
                    <span>الرئيسية</span>
                </a>
                <a href="{{ route('admin.users.index') }}" class="flex items-center px-4 py-3 hover:bg-indigo-800 {{ request()->routeIs('admin.users.*') ? 'bg-indigo-800' : '' }}">
                    <span>المستخدمون</span>
                </a>
                <a href="{{ route('admin.disputes.index') }}" class="flex items-center px-4 py-3 hover:bg-indigo-800 {{ request()->routeIs('admin.disputes.*') ? 'bg-indigo-800' : '' }}">
                    <span>النزاعات</span>
                    @if($pendingDisputes = \App\Models\Dispute::where('status', 'pending')->count())
                        <span class="mr-auto bg-red-500 text-xs px-2 py-1 rounded-full">{{ $pendingDisputes }}</span>
                    @endif
                </a>
                <a href="{{ route('admin.cashouts.index') }}" class="flex items-center px-4 py-3 hover:bg-indigo-800 {{ request()->routeIs('admin.cashouts.*') ? 'bg-indigo-800' : '' }}">
                    <span>طلبات السحب</span>
                    @if($pendingCashouts = \App\Models\CashoutRequest::where('status', 'pending')->count())
                        <span class="mr-auto bg-yellow-500 text-xs px-2 py-1 rounded-full">{{ $pendingCashouts }}</span>
                    @endif
                </a>
                <a href="{{ route('admin.kyc.index') }}" class="flex items-center px-4 py-3 hover:bg-indigo-800 {{ request()->routeIs('admin.kyc.*') ? 'bg-indigo-800' : '' }}">
                    <span>طلبات KYC</span>
                </a>
                <div class="border-t border-indigo-800 my-4"></div>
                <a href="{{ route('admin.reports.transactions') }}" class="flex items-center px-4 py-3 hover:bg-indigo-800">
                    <span>تقرير المعاملات</span>
                </a>
                <a href="{{ route('admin.reports.peacelinks') }}" class="flex items-center px-4 py-3 hover:bg-indigo-800">
                    <span>تقرير PeaceLinks</span>
                </a>
                <a href="{{ route('admin.reports.revenue') }}" class="flex items-center px-4 py-3 hover:bg-indigo-800">
                    <span>تقرير الإيرادات</span>
                </a>
            </nav>
        </aside>

        <!-- Main Content -->
        <main class="flex-1 overflow-y-auto">
            <!-- Top Bar -->
            <header class="bg-white shadow-sm px-6 py-4 flex items-center justify-between">
                <h2 class="text-xl font-semibold text-gray-800">@yield('title')</h2>
                <div class="flex items-center gap-4">
                    <span class="text-gray-600">{{ auth()->user()->name }}</span>
                    <form action="{{ route('logout') }}" method="POST" class="inline">
                        @csrf
                        <button type="submit" class="text-red-600 hover:text-red-800">خروج</button>
                    </form>
                </div>
            </header>

            <!-- Page Content -->
            <div class="p-6">
                @if(session('success'))
                    <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded mb-4">
                        {{ session('success') }}
                    </div>
                @endif

                @if(session('error'))
                    <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded mb-4">
                        {{ session('error') }}
                    </div>
                @endif

                @yield('content')
            </div>
        </main>
    </div>
</body>
</html>
*/
