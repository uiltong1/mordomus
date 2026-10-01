<?php

use Illuminate\Support\Facades\Route;
use Mordomus\Financial\Http\Controllers\BillController;
use Mordomus\Financial\Http\Controllers\BillOccurrenceController;
use Mordomus\Financial\Http\Controllers\BillSummaryController;
use Mordomus\Financial\Http\Controllers\SplitController;
use Mordomus\Financial\Http\Controllers\SplitRuleController;

/*
|--------------------------------------------------------------------------
| Módulo Financial — /api/v1/financial/*
|--------------------------------------------------------------------------
| Leitura é livre para qualquer morador ativo da residência: a lista de contas
| é o que todo mundo precisa ver. Registrar pagamento é `bills.pay` e não
| `bills.manage`, porque pagar é tarefa de morador e reconfigurar a casa é
| do owner.
|
| A cadência (dia do vencimento e antecedência do aviso) é escrita pelo módulo
| Scheduling, dono do cálculo de data (ADR-003/ADR-011).
|
| A divisão de cota-parte é escrita por `splits.manage` e lida por qualquer
| morador: a capability `splits.manage` é a do dono da casa, e é ele quem
| cadastra a regra e anota a baixa de quem já pagou a parte dele.
|
| A leitura da divisão fica fora do grupo de capability porque a condição é
| "ou" — administra a divisão ou só consulta a cota própria — e o middleware de
| capability exige todas as capabilities da rota. A escolha é do `FormRequest`.
*/

Route::middleware('auth:jwt')->group(function () {
    Route::middleware('tenant')->group(function () {
        Route::get('/bills', [BillController::class, 'index']);
        Route::get('/bills/{bill}', [BillController::class, 'show']);
        Route::get('/occurrences', [BillOccurrenceController::class, 'index']);
        Route::get('/summary', [BillSummaryController::class, 'summary']);
        Route::get('/split-rules', [SplitRuleController::class, 'index']);
        Route::get('/occurrences/{billOccurrence}/split', [SplitController::class, 'show']);

        Route::middleware('capability:bills.manage')->group(function () {
            Route::post('/bills', [BillController::class, 'store']);
            Route::patch('/bills/{bill}', [BillController::class, 'update']);
            Route::post('/occurrences', [BillOccurrenceController::class, 'store']);
        });

        Route::middleware('capability:splits.manage')->group(function () {
            Route::put('/split-rules', [SplitRuleController::class, 'update']);
            Route::patch('/occurrences/{billOccurrence}/split', [SplitController::class, 'settle']);
        });

        Route::middleware('capability:bills.pay')->group(function () {
            Route::post('/occurrences/{billOccurrence}/paid', [BillOccurrenceController::class, 'pay']);
        });
    });
});
