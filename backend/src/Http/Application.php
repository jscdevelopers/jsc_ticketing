<?php
declare(strict_types=1);
namespace App\Http;

use App\Config\Database;
use App\Security\Authenticator;
use App\Tickets\TicketReader;
use PDO;
use Throwable;

final class Application
{
    public function __construct(private ?PDO $db = null) {}

    public function handle(Request $request): Response
    {
        $requestId = bin2hex(random_bytes(16));
        try {
            $router = new Router('/api/v1');
            $router->get('/health/live', static fn () => Response::json(['service' => 'jsc-ticketing', 'alive' => true]));
            $router->get('/health/ready', function (): Response {
                $this->db()->query('SELECT 1 FROM api_tokens LIMIT 0');
                return Response::json(['ready' => true]);
            });
            $secure = function (Request $incoming, callable $next): Response {
                $incoming->user = (new Authenticator($this->db()))->actor($incoming);
                return $next($incoming);
            };
            $router->get('/me', static fn (Request $r) => Response::json($r->user))->middleware($secure);
            $router->get('/tickets', fn (Request $r) => Response::json(
                (new TicketReader($this->db()))->listing($r->user['id'], $r->query)
            ))->middleware($secure);
            $router->get('/tickets/{id}', fn (Request $r) => Response::json(
                (new TicketReader($this->db()))->detail($r->user['id'], $r->params['id'])
            ))->middleware($secure);
            if (isset($request->attributes['body_parse_error'])) {
                throw new ApiException(422, 'invalid_json', 'Malformed JSON request body.');
            }
            $response = $router->dispatch($request);
        } catch (ApiException $error) {
            $response = Response::json(['error' => $error->getMessage(), 'error_code' => $error->errorCode], $error->status);
        } catch (Throwable $error) {
            error_log(json_encode(['request_id' => $requestId, 'event' => 'request_failed', 'exception' => $error::class]));
            $response = Response::json(['error' => 'Service temporarily unavailable.', 'error_code' => 'service_unavailable'], 503);
        }
        $response->headers += [
            'X-Request-ID' => $requestId,
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'",
        ];
        if ($response->status === 401) {
            $response->headers['WWW-Authenticate'] = 'Bearer';
        }
        if ($request->method === 'HEAD') {
            $response->body = '';
        }
        return $response;
    }

    private function db(): PDO
    {
        return $this->db ??= Database::connection();
    }
}
