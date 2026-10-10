<?php

namespace Drinks\Controller\Traits;

trait JsonResponseTrait
{
    protected function jsonResponse(array $payload, $statusCode = 200)
    {
        $response = $this->getResponse();
        $headers = $response->getHeaders();
        if (!$headers->has('Content-Type')) {
            $headers->addHeaderLine('Content-Type', 'application/json');
        }
        return $response->setStatusCode($statusCode)->setContent(json_encode($payload));
    }

    protected function jsonError($statusCode, $message, array $extra = [])
    {
        return $this->jsonResponse(array_merge(['success' => false, 'error' => $message], $extra), $statusCode);
    }

    /**
     * JSON 405 response for non-POST requests, or null when the request is a POST.
     */
    protected function rejectNonPost()
    {
        return $this->getRequest()->isPost() ? null : $this->jsonError(405, 'POST required.');
    }
}
