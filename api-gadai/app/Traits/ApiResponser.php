<?php

namespace App\Traits;

trait ApiResponser
{
    protected function successResponse($data, $message = "Success", $reference = "OK", $code = 200)
    {
        return response()->json([
            'payload' => [
                'error' => false,
                'message' => $message,
                'reference' => $reference,
                'data' => $data
            ]
        ], $code);
    }

    protected function errorResponse($message, $code = 400, $reference = "ERROR")
    {
        return response()->json([
            'payload' => [
                'error' => true,
                'message' => $message,
                'reference' => $reference,
                'data' => null
            ]
        ], $code);
    }
}