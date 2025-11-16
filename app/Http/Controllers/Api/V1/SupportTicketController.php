<?php

namespace App\Http\Controllers\Api\V1;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use App\Http\Helpers\Response;
use App\Models\UserSupportChat;
use App\Models\UserSupportTicket;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Validator;
use App\Models\UserSupportTicketAttachment;
use App\Events\Admin\SupportConversationEvent;
use App\Http\Helpers\Api\Helpers as ApiResponse;

class SupportTicketController extends Controller
{
    /**
     * Display a listing of the support tickets.
     *
     * @method GET
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        $support_tickets = UserSupportTicket::authTickets()
            ->orderByDesc("id")
            ->paginate(10);

        $data = [
            'support_tickets' => $support_tickets,
        ];

        $message = ['success' => [__('Support tickets retrieved successfully')]];

        return ApiResponse::success($message, $data);
    }

    /**
     * Store a newly created support ticket.
     *
     * @method POST
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'subject'           => "required|string|max:255",
            'desc'              => "required|string|max:5000",
            'attachment.*'      => "nullable|file|max:204800",
        ]);

        if ($validator->fails()) {
            $error = ['error' => $validator->errors()->all()];
            return ApiResponse::validation($error);
        }

        $validated = $validator->validated();
        $validated['token']         = 'ST' . getTrxNum();
        $validated['user_id']       = auth()->user()->id;
        $validated['name']          = auth()->user()->firstname . " " . auth()->user()->lastname;
        $validated['email']         = auth()->user()->email;
        $validated['status']        = 0;
        $validated['created_at']    = now();
        $validated = Arr::except($validated, ['attachment']);

        try {
            $support_ticket_id = UserSupportTicket::insertGetId($validated);
        } catch (Exception $e) {
            $error = ['error' => [__('Something went wrong! Please try again')]];
            return ApiResponse::error($error);
        }

        // Handle file attachments
        if ($request->hasFile('attachment')) {
            $attachment = [];
            $files_link = [];

            // Get files - handle both single file and array of files
            $files = $request->file("attachment");

            // Convert single file to array for consistent processing
            if (!is_array($files)) {
                $files = [$files];
            }

            foreach ($files as $item) {
                if ($item && $item->isValid()) {
                    $upload_file = upload_file($item, 'support-attachment');
                    if ($upload_file != false) {
                        $attachment[] = [
                            'user_support_ticket_id'    => $support_ticket_id,
                            'attachment'                => $upload_file['name'],
                            'attachment_info'           => json_encode($upload_file),
                            'created_at'                => now(),
                        ];

                        $files_link[] = get_files_path('support-attachment') . "/" . $upload_file['name'];
                    }
                }
            }

            // Only insert if there are valid attachments
            if (!empty($attachment)) {
                try {
                    UserSupportTicketAttachment::insert($attachment);
                } catch (Exception $e) {
                    // Rollback: Delete the ticket and uploaded files
                    UserSupportTicket::find($support_ticket_id)->delete();
                    delete_files($files_link);

                    $error = ['error' => [__('Failed to upload attachment. Please try again')]];
                    return ApiResponse::error($error);
                }
            }
        }

        $support_ticket = UserSupportTicket::find($support_ticket_id);

        $message = ['success' => [__('Support ticket created successfully!')]];
        $data = [
            'support_ticket' => $support_ticket,
        ];

        return ApiResponse::created($message, $data);
    }

    /**
     * Display the specified support ticket conversation.
     *
     * @method GET
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function conversation($id)
    {
        try {
            $support_ticket = UserSupportTicket::findOrFail($id);

            // Verify that the ticket belongs to the authenticated user
            if ($support_ticket->user_id !== auth()->user()->id) {
                $error = ['error' => [__('Unauthorized access to this support ticket')]];
                return ApiResponse::unauthorized($error);
            }

            // Get only conversations without support ticket relationship
            $conversations = UserSupportChat::where('user_support_ticket_id', $id)
                ->orderBy('id', 'asc')
                ->get()
                ->map(function ($chat) {
                    return [
                        'id' => $chat->id,
                        'user_support_ticket_id' => $chat->user_support_ticket_id,
                        'sender' => $chat->sender,
                        'sender_type' => $chat->sender_type,
                        'message' => $chat->message,
                        'receiver_type' => $chat->receiver_type,
                        'file_path' => $chat->file_path,
                        'created_at' => $chat->created_at,
                        'updated_at' => $chat->updated_at,
                        'senderImage' => $chat->senderImage,
                        'chatFile' => $chat->chatFile,
                    ];
                });

            // Get ticket attachments
            $attachments = UserSupportTicketAttachment::where('user_support_ticket_id', $id)->get();

            $data = [
                'conversations' => $conversations,
                'attachments' => $attachments,
                'attachment_path' => get_files_path('support-attachment'),
            ];

            $message = ['success' => [__('Conversations retrieved successfully')]];

            return ApiResponse::success($message, $data);
        } catch (Exception $e) {
            $error = ['error' => [__('Support ticket not found')]];
            return ApiResponse::error($error);
        }
    }

    /**
     * Send a message in support ticket conversation.
     *
     * @method POST
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function messageSend(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'message'       => 'required|string|max:2000',
            'support_token' => 'required|string',
        ]);

        if ($validator->fails()) {
            $error = ['error' => $validator->errors()->all()];
            return ApiResponse::validation($error);
        }

        $validated = $validator->validated();

        $support_ticket = UserSupportTicket::notSolved($validated['support_token'])->first();

        if (!$support_ticket) {
            $error = ['error' => [__('This support ticket is closed')]];
            return ApiResponse::error($error);
        }

        // Verify that the ticket belongs to the authenticated user
        if ($support_ticket->user_id !== auth()->user()->id) {
            $error = ['error' => [__('Unauthorized access to this support ticket')]];
            return ApiResponse::unauthorized($error);
        }

        $data = [
            'user_support_ticket_id' => $support_ticket->id,
            'sender'                 => auth()->user()->id,
            'sender_type'            => "USER",
            'message'                => $validated['message'],
            'receiver_type'          => "ADMIN",
        ];

        try {
            $chat_data = UserSupportChat::create($data);
        } catch (Exception $e) {
            $error = ['error' => [__('Message sending failed! Please try again')]];
            return ApiResponse::error($error);
        }

        try {
            event(new SupportConversationEvent($support_ticket, $chat_data));
        } catch (Exception $e) {
            // Event broadcasting failed, but message was saved
            // Log this error but don't fail the request
        }

        $message = ['success' => [__('Message sent successfully!')]];
        $response_data = [
            'chat' => $chat_data,
        ];

        return ApiResponse::success($message, $response_data);
    }
}
