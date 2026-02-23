<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;

class AdminSettingsController extends Controller
{
    /**
     * @var array<string, array<int, string>>
     */
    private const GROUPED_SETTINGS = [
        'mail' => [
            'MAIL_MAILER',
            'MAIL_SCHEME',
            'MAIL_ENCRYPTION',
            'MAIL_HOST',
            'MAIL_PORT',
            'MAIL_USERNAME',
            'MAIL_PASSWORD',
            'MAIL_FROM_ADDRESS',
            'MAIL_FROM_NAME',
        ],
        'stripe' => [
            'STRIPE_SECRET_KEY',
            'STRIPE_PUBLISHABLE_KEY',
            'STRIPE_WEBHOOK_SECRET',
            'STRIPE_SUCCESS_URL',
            'STRIPE_CANCEL_URL',
        ],
        'serper' => [
            'SERPER_API_KEY',
            'SEARCH_LLM_FALLBACK_TO_SERPER',
        ],
        'google_places' => [
            'GOOGLE_PLACES_API_KEY',
        ],
        'ai' => [
            'LLM_PROVIDER',
            'SEARCH_PROVIDER',
            'OPENAI_API_KEY',
            'OPENAI_MODEL',
            'OPENAI_BASE_URL',
            'OPENROUTER_API_KEY',
            'OPENROUTER_MODEL',
            'OPENROUTER_BASE_URL',
            'OPENROUTER_SITE_URL',
            'OPENROUTER_APP_TITLE',
        ],
        'google_auth' => [
            'GOOGLE_CLIENT_ID',
            'GOOGLE_CLIENT_SECRET',
        ],
    ];

    public function show(): JsonResponse
    {
        $settings = [];

        foreach (self::GROUPED_SETTINGS as $group => $keys) {
            $settings[$group] = $this->readGroupValues($keys);
        }

        return response()->json([
            'status' => 'success',
            'settings' => $settings,
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $rules = [];
        foreach (self::GROUPED_SETTINGS as $group => $keys) {
            $rules[$group] = 'sometimes|array';
            foreach ($keys as $key) {
                $rules[$group.'.'.$key] = 'nullable|string|max:5000';
            }
        }

        $validated = $request->validate($rules);

        $updates = [];
        foreach (self::GROUPED_SETTINGS as $group => $keys) {
            $groupData = $validated[$group] ?? null;
            if (!is_array($groupData)) {
                continue;
            }

            foreach ($keys as $key) {
                if (!array_key_exists($key, $groupData)) {
                    continue;
                }

                $updates[$key] = $this->normalizeEnvValue($groupData[$key]);
            }
        }

        if (empty($updates)) {
            return response()->json([
                'status' => 'error',
                'message' => 'No settings provided for update.',
            ], 422);
        }

        $this->writeEnvValues($updates);

        if (app()->configurationIsCached()) {
            Artisan::call('config:clear');
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Settings updated successfully.',
            'settings' => $this->show()->getData(true)['settings'],
        ]);
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<string, string>
     */
    private function readGroupValues(array $keys): array
    {
        $path = $this->envFilePath();
        $values = [];
        foreach ($keys as $key) {
            $values[$key] = '';
        }

        if (!File::exists($path)) {
            return $values;
        }

        $lines = preg_split('/\R/', (string) File::get($path)) ?: [];
        foreach ($lines as $line) {
            if (!preg_match('/^\s*([A-Z0-9_]+)\s*=\s*(.*)\s*$/', $line, $matches)) {
                continue;
            }

            $key = $matches[1];
            if (!array_key_exists($key, $values)) {
                continue;
            }

            $values[$key] = $this->parseEnvValue($matches[2]);
        }

        return $values;
    }

    /**
     * @param  array<string, string>  $updates
     */
    private function writeEnvValues(array $updates): void
    {
        $path = $this->envFilePath();

        if (!File::exists($path)) {
            File::put($path, '');
        }

        $lines = preg_split('/\R/', (string) File::get($path)) ?: [];
        $seen = [];

        foreach ($lines as $index => $line) {
            if (!preg_match('/^\s*([A-Z0-9_]+)\s*=/', $line, $matches)) {
                continue;
            }

            $key = $matches[1];
            if (!array_key_exists($key, $updates)) {
                continue;
            }

            $lines[$index] = $key.'='.$this->formatEnvValue($updates[$key]);
            $seen[$key] = true;
        }

        foreach ($updates as $key => $value) {
            if (array_key_exists($key, $seen)) {
                continue;
            }

            $lines[] = $key.'='.$this->formatEnvValue($value);
        }

        $content = implode(PHP_EOL, $lines);
        if ($content === '' || !str_ends_with($content, PHP_EOL)) {
            $content .= PHP_EOL;
        }

        File::put($path, $content);
    }

    private function envFilePath(): string
    {
        $configuredPath = config('admin.settings_env_file');

        if (is_string($configuredPath) && trim($configuredPath) !== '') {
            return $configuredPath;
        }

        return base_path('.env');
    }

    private function normalizeEnvValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return is_string($value) ? $value : (string) $value;
    }

    private function parseEnvValue(string $rawValue): string
    {
        $value = trim($rawValue);

        if ($value === 'null') {
            return '';
        }

        $first = substr($value, 0, 1);
        $last = substr($value, -1);

        if (($first === '"' && $last === '"') || ($first === '\'' && $last === '\'')) {
            return stripcslashes(substr($value, 1, -1));
        }

        return $value;
    }

    private function formatEnvValue(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (!preg_match('/\s|#|"|\'|=/', $value)) {
            return $value;
        }

        return '"'.addcslashes($value, "\\\"").'"';
    }
}
