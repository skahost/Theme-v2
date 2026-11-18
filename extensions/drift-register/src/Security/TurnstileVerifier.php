<?php

namespace DriftRegister\Security;

use DriftRegister\Settings\RegistrationSettings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class TurnstileVerifier
{
    private const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    public function verify(Request $request, RegistrationSettings $settings): void
    {
        if (! $settings->turnstileEnabled()) {
            return;
        }

        $siteKey = $settings->turnstileSiteKey();
        $secretKey = $settings->turnstileSecretKey();
        if ($siteKey === '' || $secretKey === '') {
            throw new HttpException(503, 'Registration security verification is not configured.');
        }

        $token = $request->input('cf-turnstile-response');
        if (! is_string($token) || trim($token) === '') {
            $this->invalidToken();
        }

        $form = [
            'secret' => $secretKey,
            'response' => trim($token),
        ];
        $ip = $request->ip();
        if ($ip !== null) {
            $form['remoteip'] = $ip;
        }

        try {
            $response = Http::asForm()
                ->connectTimeout(2)
                ->timeout(5)
                ->post(self::VERIFY_URL, $form);
        } catch (ConnectionException $exception) {
            Log::warning('Cloudflare Turnstile verification could not be reached.', [
                'exception' => $exception::class,
            ]);

            throw new HttpException(503, 'Registration security verification is temporarily unavailable.', $exception);
        }

        if (! $response->successful()) {
            Log::warning('Cloudflare Turnstile verification returned an unsuccessful HTTP status.', [
                'status' => $response->status(),
            ]);

            throw new HttpException(503, 'Registration security verification is temporarily unavailable.');
        }

        $result = $response->json();
        if (! is_array($result)
            || ($result['success'] ?? false) !== true
            || ! is_string($result['hostname'] ?? null)
            || strtolower($result['hostname']) !== strtolower($request->getHost())
            || ($result['action'] ?? null) !== 'register') {
            $this->invalidToken();
        }
    }

    private function invalidToken(): never
    {
        throw ValidationException::withMessages([
            'cf-turnstile-response' => 'The security check could not be verified. Please try again.',
        ]);
    }
}
