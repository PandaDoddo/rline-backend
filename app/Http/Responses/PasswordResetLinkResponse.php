<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\FailedPasswordResetLinkRequestResponse;
use Laravel\Fortify\Contracts\SuccessfulPasswordResetLinkRequestResponse;

class PasswordResetLinkResponse implements FailedPasswordResetLinkRequestResponse, SuccessfulPasswordResetLinkRequestResponse
{
    public function toResponse($request)
    {
        return response()->json(['message' => 'Если учётная запись существует, инструкция отправлена на её адрес.']);
    }
}
