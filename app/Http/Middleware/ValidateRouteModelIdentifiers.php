<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\{Reflector, Str};
use Symfony\Component\HttpFoundation\Response;

/** Reject invalid numeric model keys before PostgreSQL attempts implicit binding. */
class ValidateRouteModelIdentifiers
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if ($route) {
            $parameters = $route->parameters();
            foreach ($route->signatureParameters(['subClass' => Model::class]) as $parameter) {
                $name = $parameter->getName();
                if (!array_key_exists($name, $parameters)) { $name = Str::snake($name); }
                if (!array_key_exists($name, $parameters)) { continue; }
                $value = $parameters[$name];
                if ($value instanceof Model || $value === null) { continue; }
                $model = app(Reflector::getParameterClassName($parameter));
                $field = $route->bindingFieldFor($name) ?? $model->getRouteKeyName();
                if ($model->getKeyType() !== 'int' || $field !== $model->getKeyName()) { continue; }
                // PostgreSQL bigint has a signed 64-bit boundary; integer rules
                // or ctype_digit alone still allow a database-overflowing value.
                $text = is_int($value) ? (string) $value : $value;
                abort_unless(is_string($text) && preg_match('/^[0-9]{1,19}$/D', $text), 404);
                $normal = ltrim($text, '0');
                abort_unless($normal !== '' && (strlen($normal) < 19 || strcmp($normal, '9223372036854775807') <= 0), 404);
            }
        }
        return $next($request);
    }
}
