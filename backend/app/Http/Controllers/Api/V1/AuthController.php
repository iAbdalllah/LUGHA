<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Fortify\CreateNewUser;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /** Token abilities for app clients (device-scoped tokens arrive with the device registry in Phase 2). */
    public const ABILITIES = ['catalog:read', 'library:read', 'commerce'];

    public static function userPayload(User $u): array
    {
        return ['id' => $u->public_id, 'name' => $u->name, 'email' => $u->email, 'locale' => $u->locale,
            'email_verified' => $u->hasVerifiedEmail(), 'marketing_opt_in' => $u->marketing_opt_in];
    }

    public function register(Request $request, CreateNewUser $create)
    {
        $user = $create->create($request->all());
        event(new Registered($user)); // sends the verification email

        return response()->json(['user' => self::userPayload($user)], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string'], 'device_name' => ['required', 'string', 'max:80']]);
        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        if (! $user->isActive()) {
            throw ValidationException::withMessages(['email' => __('This account is not active. Please contact support.')]);
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return response()->json([
            'token' => $user->createToken($data['device_name'], self::ABILITIES)->plainTextToken,
            'user' => self::userPayload($user),
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->noContent();
    }

    public function me(Request $request)
    {
        return response()->json(['user' => self::userPayload($request->user())]);
    }

    public function update(Request $request)
    {
        $data = $request->validate(['name' => ['sometimes', 'string', 'max:120'], 'locale' => ['sometimes', 'in:ar,en'], 'marketing_opt_in' => ['sometimes', 'boolean']]);
        $request->user()->fill($data)->save();

        return response()->json(['user' => self::userPayload($request->user())]);
    }

    public function resendVerification(Request $request)
    {
        if (! $request->user()->hasVerifiedEmail()) {
            $request->user()->sendEmailVerificationNotification();
        }

        return response()->json(['status' => 'sent'], 202);
    }
}
