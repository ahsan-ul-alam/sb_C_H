<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ClientApiIntegration;
use App\Services\ClientInformation;
use App\Services\ClientInformationOtp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The only two externally exposed endpoints of the client information integration.
 */
class ClientInformationController extends Controller
{
    public function request(Request $request): JsonResponse
    {
        $issued = ClientInformationOtp::request($this->integration($request), $request->input('sb_number'));

        return response()->json([
            'success' => true,
            'message' => 'If the request is eligible, a verification email will be sent.',
            'request_id' => $issued['request_id'],
            'expires_in' => $issued['expires_in'],
        ])->header('Cache-Control', 'no-store');
    }

    public function verify(Request $request): JsonResponse
    {
        $client = ClientInformationOtp::verify($this->integration($request), $request->input('sb_number'), $request->input('request_id'), $request->input('verification_code'));

        if ($client === null) {
            return response()->json(['success' => false, 'message' => 'Verification failed.'], 422)->header('Cache-Control', 'no-store');
        }

        return response()->json(['success' => true, 'data' => ClientInformation::for($client)])->header('Cache-Control', 'no-store');
    }

    private function integration(Request $request): ClientApiIntegration
    {
        return $request->attributes->get('client_api_integration');
    }
}
