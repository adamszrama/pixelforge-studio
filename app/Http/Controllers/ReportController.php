<?php

namespace App\Http\Controllers;

use App\Domain\Billing\BuildSpendReport;
use App\Http\Requests\ReportRequest;
use Inertia\Inertia;
use Inertia\Response;

final class ReportController
{
    public function __invoke(ReportRequest $request, BuildSpendReport $report): Response
    {
        return Inertia::render('Reports', $report->execute($request->start(), $request->end()));
    }
}
