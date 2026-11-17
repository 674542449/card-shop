<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Credentials belong in the request body, never in URLs or referrers. */
class BuyerCredentialInput
{
    public static function body(Request $request): array
    {
        self::rejectUrlCredentials($request);

        return $request->isJson() ? $request->json()->all() : $request->request->all();
    }

    public static function rejectUrlCredentials(Request $request): void
    {
        $errors = [];
        foreach (['email', 'query_password'] as $field) {
            if ($request->query->has($field)) {
                $errors[$field] = '请通过 POST 请求正文传递邮箱和查询密码，不要放在 URL 中。';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
