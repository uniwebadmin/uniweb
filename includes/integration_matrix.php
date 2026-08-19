<?php
declare(strict_types=1);

/**
 * Gateway × operation integration matrix — scaffold registry only.
 * Canonical logic: includes/integration_matrix_workflow.php (diagram audit #11).
 */

function integrationMatrixGateways(): array
{
    if (!function_exists('integrationMatrixPartnerLabels')) {
        require_once __DIR__ . '/integration_matrix_workflow.php';
    }
    return integrationMatrixPartnerLabels();
}

function integrationMatrixOperations(): array
{
    if (!function_exists('integrationMatrixOperationDefinitions')) {
        require_once __DIR__ . '/integration_matrix_workflow.php';
    }
    return integrationMatrixOperationDefinitions();
}

/** @return array{status:string,note:string} */
function integrationMatrixCellStatus(string $gateway, string $operation): array
{
    if (!function_exists('integrationMatrixEvaluateCell')) {
        require_once __DIR__ . '/integration_matrix_workflow.php';
    }
    return integrationMatrixEvaluateCell($gateway, $operation);
}

function integrationMatrixSummary(): array
{
    if (!function_exists('integrationMatrixBuildSummary')) {
        require_once __DIR__ . '/integration_matrix_workflow.php';
    }
    return integrationMatrixBuildSummary();
}

function integrationMatrixOpApplies(string $gateway, string $operation): bool
{
    if (!function_exists('integrationMatrixOperationApplies')) {
        require_once __DIR__ . '/integration_matrix_workflow.php';
    }
    return integrationMatrixOperationApplies($gateway, $operation);
}

function integrationMatrixStatusBadge(string $status): string
{
    if (!function_exists('integrationMatrixStatusBadgeHtml')) {
        require_once __DIR__ . '/integration_matrix_workflow.php';
    }
    return integrationMatrixStatusBadgeHtml($status);
}
