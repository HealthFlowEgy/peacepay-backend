<?php

namespace App\Http\Controllers\User;

use App\Http\Controllers\Controller;
use App\Models\Admin\BasicSettings;
use App\Models\Policy;
use Illuminate\Http\Request;

class PolicyController extends Controller
{
    /**
     * Display a listing of the policies.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $policies = Policy::mine()->latest()->paginate(10);
        return view('user.policies.index', compact('policies'));
    }

    /**
     * Show the form for creating a new policy.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        return view('user.policies.create');
    }

    /**
     * Store a newly created policy in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $basic_setting = BasicSettings::first();
        $user          = auth()->user();
        if ($basic_setting->kyc_verification) {
            if ($user->kyc_verified == 0) {
                return redirect()->route('user.authorize.kyc')->with(['error' => [__('Please submit kyc information')]]);
            } elseif ($user->kyc_verified == 2) {
                return redirect()->route('user.authorize.kyc')->with(['error' => [__('Please wait before admin approved your kyc information')]]);
            } elseif ($user->kyc_verified == 3) {
                return redirect()->route('user.authorize.kyc')->with(['error' => [__('Admin rejected your kyc information, Please re-submit again')]]);
            }
        }
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'fields' => 'required',
        ]);


        Policy::create($request->except('fields') + [
            'fields' => json_encode($request->fields),
            'user_id' => auth()->user()->id,
        ]);

        return redirect()->route('user.policies.index')
            ->with('success', 'Policy created successfully.');
    }

    /**
     * Display the specified policy.
     *
     * @param  \App\Models\Policy  $policy
     * @return \Illuminate\Http\Response
     */
    public function show(Policy $policy)
    {
        return view('user.policies.show', compact('policy'));
    }

    /**
     * Show the form for editing the specified policy.
     *
     * @param  \App\Models\Policy  $policy
     * @return \Illuminate\Http\Response
     */
    public function edit(Policy $policy)
    {
        return view('user.policies.edit', compact('policy'));
    }

    /**
     * Update the specified policy in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Policy  $policy
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, Policy $policy)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'required|string',
            'fields' => 'required',
        ]);

        $policy->update($request->except('fields') + [
            'fields' => json_encode($request->fields),
        ]);

        return redirect()->route('user.policies.index')
            ->with('success', 'Policy updated successfully.');
    }

    /**
     * Remove the specified policy from storage.
     *
     * @param  \App\Models\Policy  $policy
     * @return \Illuminate\Http\Response
     */
    public function destroy(Policy $policy)
    {
        $policy->delete();

        return redirect()->route('user.policies.index')
            ->with('success', 'Policy deleted successfully.');
    }
}
