<?php

declare(strict_types=1);

namespace App\Api\Controllers;

use App\Auth\ApiAuth;
use App\Http\Request;
use App\Http\Response;
use App\Services\SurveyService;

final class FormController
{
    private static function service(): SurveyService
    {
        return new SurveyService();
    }

    /** List published forms (for mobile download). */
    public static function index(): never
    {
        ApiAuth::requireAuth();
        $service = self::service();
        $forms = $service->publishedForms();
        Response::ok(['updated_at' => date('c'), 'forms' => $forms]);
    }

    /** Download a single form definition by code or id. */
    public static function show(array $params): never
    {
        ApiAuth::requireAuth();
        $identifier = (string) $params['identifier'];
        $service = self::service();
        $pdo = \App\Database\Connection::instance();

        $stmt = $pdo->prepare('SELECT * FROM survey_forms WHERE code = :c OR id = :id LIMIT 1');
        $stmt->execute(['c' => $identifier, 'id' => ctype_digit($identifier) ? (int) $identifier : 0]);
        $form = $stmt->fetch();

        if ($form === false) {
            Response::notFound('Survey form not found.');
        }
        if ($form['status'] !== 'published') {
            Response::error('Survey form is not published.', 403);
        }

        $def = $service->formDefinition((int) $form['id']);
        Response::ok($def);
    }
}
