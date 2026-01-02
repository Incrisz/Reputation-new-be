<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\View\View;

class SwaggerController extends Controller
{
    /**
     * Display Swagger UI
     */
    public function index(): View
    {
        return view('swagger.index');
    }

    /**
     * Display diagnostic info
     */
    public function status()
    {
        $specPath = storage_path('api-docs/openapi.yaml');
        
        return response()->json([
            'status' => 'ok',
            'swagger' => [
                'ui_url' => url('/api/docs'),
                'spec_url' => url('/api/docs/spec'),
            ],
            'files' => [
                'openapi_spec' => file_exists($specPath),
                'spec_path' => $specPath,
            ],
            'endpoints' => [
                'api' => url('/api/reputation/scan'),
            ],
        ]);
    }

    /**
     * Get OpenAPI specification as YAML
     */
    public function spec()
    {
        $specPath = storage_path('api-docs/openapi.yaml');
        
        if (!file_exists($specPath)) {
            abort(404, 'OpenAPI specification not found');
        }

        $yaml = file_get_contents($specPath);
        
        return response($yaml, 200, [
            'Content-Type' => 'application/yaml; charset=utf-8',
            'Access-Control-Allow-Origin' => '*',
        ]);
    }

    /**
     * Get OpenAPI specification as JSON
     */
    public function specJson()
    {
        $specPath = storage_path('api-docs/openapi.yaml');
        
        if (!file_exists($specPath)) {
            abort(404, 'OpenAPI specification not found');
        }

        $yaml = file_get_contents($specPath);
        
        return response($yaml, 200, [
            'Content-Type' => 'application/yaml; charset=utf-8',
        ]);
    }
}
