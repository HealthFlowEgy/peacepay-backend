<?php

namespace App\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

trait ResponseTrait
{
    /**
     * Send a standardized success response for mobile apps
     */
    protected function successResponse($data = null, $message = 'Success', $statusCode = 200)
    {
        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'timestamp' => now()->toISOString(),
            'status_code' => $statusCode
        ], $statusCode);
    }

    /**
     * Send a standardized error response for mobile apps
     */
    protected function errorResponse($message = 'Error', $errors = null, $statusCode = 400, $errorCode = null)
    {
        $response = [
            'success' => false,
            'message' => $message,
            'timestamp' => now()->toISOString(),
            'status_code' => $statusCode
        ];

        if ($errors) {
            $response['errors'] = $errors;
        }

        if ($errorCode) {
            $response['error_code'] = $errorCode;
        }

        return response()->json($response, $statusCode);
    }

}