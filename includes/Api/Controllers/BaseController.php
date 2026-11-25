<?php

namespace FeedAgentX\Api\Controllers;

defined('ABSPATH') || exit;

use FeedAgentX\Api\ResponseFormatter;
use FeedAgentX\Infra\ErrorDiagnostics;
use FeedAgentX\Infra\Exceptions\FeedAgentXException;
use FeedAgentX\Infra\Exceptions\ProductMappingException;
use FeedAgentX\Infra\Logger;

/**
 * Wraps controller actions in uniform exception handling.
 *
 * Subclasses call `$this->run($request, fn ($req) => ...)` and any thrown
 * FeedAgentXException becomes a structured JSON error; any other Throwable
 * becomes a 500 with a generic message and ErrorDiagnostics details.
 */
abstract class BaseController
{
    /**
     * @param callable(\WP_REST_Request): \WP_REST_Response|array $action
     */
    protected function run(\WP_REST_Request $request, callable $action): \WP_REST_Response
    {
        try {
            $result = $action($request);
            if ($result instanceof \WP_REST_Response) {
                return $result;
            }
            if (is_array($result)) {
                return ResponseFormatter::success($result);
            }
            // Defensive: unknown return type → coerce to empty success.
            return ResponseFormatter::success([]);
        } catch (FeedAgentXException $e) {
            Logger::warning('Controller domain error', [
                'route' => $request->get_route(),
                'code' => $e->get_error_code(),
                'message' => $e->getMessage(),
                'context' => $e->get_context(),
            ]);
            return ResponseFormatter::from_exception($e);
        } catch (\Throwable $e) {
            // Every route checks the read token before it runs, so only the
            // FeedAgentX connection gets these details: which code failed
            // (often another plugin's hook), shortened and without paths.
            $cause = $e instanceof ProductMappingException && $e->getPrevious() !== null ? $e->getPrevious() : $e;
            $details = ErrorDiagnostics::describe($cause);
            if ($e instanceof ProductMappingException) {
                $details['product_id'] = $e->product_id;
            }

            Logger::error('Unhandled controller exception', [
                'route' => $request->get_route(),
                'file' => $cause->getFile() . ':' . $cause->getLine(),
            ] + $details);

            return ResponseFormatter::error(
                'internal_error',
                __('An unexpected error occurred.', 'feedagentx-connect'),
                500,
                $details
            );
        }
    }
}
