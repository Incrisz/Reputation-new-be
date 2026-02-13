<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset as PasswordResetEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function register(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            $user = User::create([
                ...$validated,
                'registration_provider' => 'email',
            ]);

            $this->updateLoginTracking($user, $request, 'email');
            $this->recordAuthEvent($user, $request, 'register', 'email');

            return response()->json([
                'status' => 'success',
                'message' => 'Account created successfully.',
                'user' => $this->serializeUser($user),
            ], 201);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid registration data.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function login(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'string', 'email'],
                'password' => ['required', 'string'],
            ]);

            $user = User::query()
                ->where('email', $validated['email'])
                ->first();

            if (!$user || !Hash::check($validated['password'], $user->password)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid email or password.',
                ], 422);
            }

            $this->updateLoginTracking($user, $request, 'email');
            $this->recordAuthEvent($user, $request, 'login', 'email');

            return response()->json([
                'status' => 'success',
                'message' => 'Signed in successfully.',
                'user' => $this->serializeUser($user),
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid login data.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function google(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'code' => ['nullable', 'string'],
                'id_token' => ['nullable', 'string'],
            ]);

            if (empty($validated['code']) && empty($validated['id_token'])) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Google auth code or id_token is required.',
                ], 422);
            }

            $idToken = $validated['id_token'] ?? $this->exchangeGoogleCodeForIdToken($validated['code']);
            if (!$idToken) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unable to verify Google account.',
                ], 422);
            }

            $googleUser = $this->verifyGoogleIdToken($idToken);
            if (!$googleUser) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Unable to verify Google account.',
                ], 422);
            }

            $user = User::query()->firstOrCreate(
                ['email' => $googleUser['email']],
                [
                    'name' => $googleUser['name'],
                    'password' => Str::random(40),
                    'registration_provider' => 'google',
                    'google_id' => $googleUser['sub'],
                    'avatar_url' => $googleUser['picture'],
                    'email_verified_at' => now(),
                ]
            );

            if ($user->google_id && $user->google_id !== $googleUser['sub']) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Google account does not match this user.',
                ], 422);
            }

            if (!$user->name && $googleUser['name']) {
                $user->name = $googleUser['name'];
            }

            if (!$user->google_id) {
                $user->google_id = $googleUser['sub'];
            }

            if (!$user->email_verified_at) {
                $user->email_verified_at = now();
            }

            if ($googleUser['picture']) {
                $user->avatar_url = $googleUser['picture'];
            }

            $user->save();

            $this->updateLoginTracking($user, $request, 'google');
            $this->recordAuthEvent($user, $request, 'login', 'google', [
                'google_id' => $googleUser['sub'],
            ]);

            return response()->json([
                'status' => 'success',
                'message' => 'Signed in with Google successfully.',
                'user' => $this->serializeUser($user),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid Google authentication data.',
                'errors' => $e->errors(),
            ], 422);
        } catch (\RuntimeException $e) {
            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        } catch (\Throwable $e) {
            \Log::error('Google auth error: '.$e->getMessage(), [
                'exception' => $e,
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Google authentication failed.',
            ], 500);
        }
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'string', 'email'],
            ]);

            $user = User::query()->where('email', $validated['email'])->first();
            $previewResetUrl = null;

            if ($user) {
                $token = Password::broker()->createToken($user);
                $resetUrl = $this->buildPasswordResetUrl($token, $user->email);

                \Log::info('Password reset link generated.', [
                    'email' => $user->email,
                    'reset_url' => $resetUrl,
                ]);

                $this->recordAuthEvent($user, $request, 'forgot_password', 'email');

                if (app()->environment(['local', 'development'])) {
                    $previewResetUrl = $resetUrl;
                }
            }

            $response = [
                'status' => 'success',
                'message' => 'If an account with that email exists, a password reset link has been sent.',
            ];

            if ($previewResetUrl) {
                $response['reset_url'] = $previewResetUrl;
            }

            return response()->json($response);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid forgot-password request data.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function resetPassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'email' => ['required', 'string', 'email'],
                'token' => ['required', 'string'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            $status = Password::reset(
                [
                    'email' => $validated['email'],
                    'token' => $validated['token'],
                    'password' => $validated['password'],
                    'password_confirmation' => $request->input('password_confirmation'),
                ],
                function (User $user, string $password) use ($request): void {
                    $user->forceFill([
                        'password' => $password,
                        'remember_token' => Str::random(60),
                    ])->save();

                    event(new PasswordResetEvent($user));

                    $this->updateLoginTracking($user, $request, 'password_reset');
                    $this->recordAuthEvent($user, $request, 'password_reset', 'email');
                }
            );

            if ($status !== Password::PASSWORD_RESET) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Invalid or expired reset token.',
                ], 422);
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Password reset successful. You can now sign in.',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid reset-password request data.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function profile(Request $request): JsonResponse
    {
        $user = $this->resolveUserFromRequest($request);
        if (!$user) {
            return response()->json([
                'status' => 'error',
                'message' => 'User not found.',
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'user' => $this->serializeUser($user),
        ]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => ['nullable', 'integer', 'exists:users,id'],
                'lookup_email' => ['nullable', 'string', 'email'],
                'name' => ['sometimes', 'required', 'string', 'max:255'],
                'email' => ['sometimes', 'required', 'string', 'email', 'max:255'],
                'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
                'company' => ['sometimes', 'nullable', 'string', 'max:255'],
                'industry' => ['sometimes', 'nullable', 'string', 'max:100'],
                'company_size' => ['sometimes', 'nullable', 'string', 'max:100'],
                'website' => ['sometimes', 'nullable', 'string', 'max:255'],
                'location' => ['sometimes', 'nullable', 'string', 'max:255'],
                'notification_preferences' => ['sometimes', 'nullable', 'array'],
            ]);

            $user = $this->resolveUserFromRequest($request);
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found.',
                ], 404);
            }

            if (array_key_exists('email', $validated)) {
                $request->validate([
                    'email' => [
                        'required',
                        'email',
                        'max:255',
                        Rule::unique('users', 'email')->ignore($user->id),
                    ],
                ]);
            }

            $fields = [
                'name',
                'email',
                'phone',
                'company',
                'industry',
                'company_size',
                'website',
                'location',
                'notification_preferences',
            ];

            $updates = [];
            foreach ($fields as $field) {
                if (array_key_exists($field, $validated)) {
                    $updates[$field] = $validated[$field];
                }
            }

            if (!empty($updates)) {
                $user->fill($updates)->save();
                $this->recordAuthEvent($user, $request, 'profile_update', 'email');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Profile updated successfully.',
                'user' => $this->serializeUser($user->fresh()),
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid profile update data.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    public function changePassword(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => ['nullable', 'integer', 'exists:users,id'],
                'lookup_email' => ['nullable', 'string', 'email'],
                'current_password' => ['required', 'string'],
                'password' => ['required', 'string', 'min:8', 'confirmed'],
            ]);

            $user = $this->resolveUserFromRequest($request);
            if (!$user) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'User not found.',
                ], 404);
            }

            if (!Hash::check($validated['current_password'], $user->password)) {
                return response()->json([
                    'status' => 'error',
                    'message' => 'Current password is incorrect.',
                ], 422);
            }

            $user->forceFill([
                'password' => $validated['password'],
                'remember_token' => Str::random(60),
            ])->save();

            $this->recordAuthEvent($user, $request, 'password_change', 'email');

            return response()->json([
                'status' => 'success',
                'message' => 'Password updated successfully.',
            ]);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid password change data.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    private function exchangeGoogleCodeForIdToken(string $code): ?string
    {
        $clientId = config('services.google.client_id');
        $clientSecret = config('services.google.client_secret');

        if (!$clientId || !$clientSecret) {
            throw new \RuntimeException('Google OAuth credentials are not configured.');
        }

        $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
            'redirect_uri' => 'postmessage',
            'grant_type' => 'authorization_code',
        ]);

        if (!$response->successful()) {
            return null;
        }

        return $response->json('id_token');
    }

    private function verifyGoogleIdToken(string $idToken): ?array
    {
        $clientId = config('services.google.client_id');
        if (!$clientId) {
            throw new \RuntimeException('Google OAuth client ID is not configured.');
        }

        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken,
        ]);

        if (!$response->successful()) {
            return null;
        }

        $payload = $response->json();
        $audience = $payload['aud'] ?? null;
        $email = $payload['email'] ?? null;
        $sub = $payload['sub'] ?? null;
        $isVerified = $payload['email_verified'] ?? false;
        $isVerified = $isVerified === true || $isVerified === 'true';

        if ($audience !== $clientId || !$email || !$sub || !$isVerified) {
            return null;
        }

        return [
            'email' => $email,
            'name' => $payload['name'] ?? Str::before($email, '@'),
            'sub' => $sub,
            'picture' => $payload['picture'] ?? null,
        ];
    }

    private function buildPasswordResetUrl(string $token, string $email): string
    {
        $frontendUrl = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/');
        $tokenQuery = urlencode($token);
        $emailQuery = urlencode($email);

        return "{$frontendUrl}/reset-password?token={$tokenQuery}&email={$emailQuery}";
    }

    private function resolveUserFromRequest(Request $request): ?User
    {
        $userId = $request->input('user_id', $request->query('user_id'));
        if ($userId) {
            return User::query()->find($userId);
        }

        $email = $request->input('lookup_email', $request->query('lookup_email'));
        if ($email) {
            return User::query()->where('email', $email)->first();
        }

        return null;
    }

    private function updateLoginTracking(User $user, Request $request, string $provider): void
    {
        $user->forceFill([
            'last_login_at' => now(),
            'last_login_ip' => $request->ip(),
            'last_login_user_agent' => $request->userAgent(),
            'last_login_provider' => $provider,
        ])->save();
    }

    private function recordAuthEvent(
        User $user,
        Request $request,
        string $eventType,
        string $provider,
        array $metadata = []
    ): void {
        $user->authEvents()->create([
            'event_type' => $eventType,
            'provider' => $provider,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'metadata' => empty($metadata) ? null : $metadata,
            'created_at' => now(),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        try {
            $validated = $request->validate([
                'user_id' => ['required', 'integer', 'exists:users,id'],
            ]);

            $user = User::query()->find($validated['user_id']);

            if ($user) {
                $this->recordAuthEvent($user, $request, 'logout', $user->last_login_provider ?? 'unknown');
            }

            return response()->json([
                'status' => 'success',
                'message' => 'Signed out successfully.',
            ], 200);
        } catch (ValidationException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid logout data.',
                'errors' => $e->errors(),
            ], 422);
        }
    }

    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'registration_provider' => $user->registration_provider,
            'avatar_url' => $user->avatar_url,
            'phone' => $user->phone,
            'company' => $user->company,
            'industry' => $user->industry,
            'company_size' => $user->company_size,
            'website' => $user->website,
            'location' => $user->location,
            'notification_preferences' => $user->notification_preferences,
            'last_login_at' => $user->last_login_at?->toISOString(),
            'last_login_provider' => $user->last_login_provider,
            'created_at' => $user->created_at?->toISOString(),
        ];
    }
}
