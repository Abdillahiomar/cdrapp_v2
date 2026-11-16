<?php

namespace App\Http\Controllers;

use App\Models\Draw;
use App\Models\DrawCeremony;
use App\Models\DrawPrize;
use App\Models\DrawWinner;
use App\Models\PrizeImage;
use App\Support\DrawEngine;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Exports des tirages au sort : liste des éligibles (par liste), gagnants et procès-verbal (par cérémonie) */
class DrawController extends Controller
{
    /** Liste figée des éligibles (CSV ; séparateur « ; » pour Excel en français) */
    public function eligibles(Draw $draw): StreamedResponse
    {
        abort_if(in_array($draw->status, ['draft', 'computing'], true), 404);

        return $this->csv($this->fileName('liste-' . $draw->id, $draw->name, 'eligibles'), ['Numéro', 'Nom', 'Transactions', 'Montant (FDJ)', 'Dernière transaction'], function () use ($draw) {
            foreach (DB::table('draw_participants')->where('draw_id', $draw->id)->orderBy('msisdn')->cursor() as $p) {
                yield [$p->msisdn, $p->name, $p->txn_count, $p->amount, $p->last_txn_at];
            }
        });
    }

    /** Toutes les sorties de la cérémonie, y compris suppléants et gagnants écartés */
    public function winners(DrawCeremony $ceremony): StreamedResponse
    {
        abort_unless($ceremony->hasStarted(), 404);

        $rows = $ceremony->draws()->with(['category', 'prizes.winners.replaces'])->get()->flatMap(
            fn (Draw $draw) => $draw->prizes->flatMap(fn ($prize) => $prize->winners->map(fn (DrawWinner $w) => [
                $draw->prize_category_id ? $draw->categoryName() : $prize->category_name,
                $prize->name,
                $w->promoted_at ? 'Suppléant promu' : DrawWinner::KINDS[$w->kind],
                $w->kind === 'extra' ? '' : $w->rank,
                $w->msisdn,
                $w->name,
                $w->txn_count,
                $w->isEffective() ? 'Oui' : 'Non',
                $w->status === 'invalidated' ? 'Écarté' : 'Valide',
                $w->invalidated_reason,
                $w->replaces?->msisdn,
                $w->created_at?->format('Y-m-d H:i:s'),
            ]))
        );

        return $this->csv(
            $this->fileName('ceremonie-' . $ceremony->id, $ceremony->name, 'gagnants'),
            ['Catégorie', 'Lot', 'Type', 'Rang', 'Numéro', 'Nom', 'Transactions', 'Gagnant retenu', 'Statut', 'Motif', 'Remplace', 'Tiré le'],
            fn () => $rows
        );
    }

    /** Procès-verbal de la cérémonie (PDF) : une section par liste d'éligibles */
    public function pv(DrawCeremony $ceremony)
    {
        abort_unless($ceremony->hasStarted(), 404);

        $ceremony->load(['creator', 'drawer', 'closer']);
        $draws  = $ceremony->draws()->with(['category', 'creator', 'computer', 'prizes.winners.replaces', 'prizes.winners.invalidator'])->get();
        $engine = app(DrawEngine::class);
        $types  = DB::table('transaction_types')->pluck('txn_type_name', 'txn_index');

        $pdf = Pdf::loadView('draws.pv', [
            'ceremony' => $ceremony,
            'draws'    => $draws,
            'images'   => $draws->flatMap->prizes->mapWithKeys(fn ($p) => [$p->id => $this->dataUri($p->image_path)]),
            'types'    => $draws->mapWithKeys(fn ($d) => [$d->id => collect($d->txn_indexes ?? [])->map(fn ($i) => $types[$i] ?? $i)->sort()->values()]),
            'verify'   => $engine->verifyCeremony($ceremony),
        ])->setPaper('a4');

        return $pdf->download($this->fileName('ceremonie-' . $ceremony->id, $ceremony->name, 'pv') . '.pdf');
    }

    /** Image du catalogue (disque privé, accès authentifié) */
    public function prizeImage(PrizeImage $prizeImage)
    {
        return $this->image($prizeImage->path);
    }

    /** Image copiée dans un tirage */
    public function lotImage(DrawPrize $drawPrize)
    {
        abort_unless($drawPrize->image_path, 404);

        return $this->image($drawPrize->image_path);
    }

    private function image(string $path)
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($path), 404);

        return $disk->response($path, null, ['Cache-Control' => 'private, max-age=86400']);
    }

    /** Image embarquée dans le PDF (dompdf ne passe pas par les routes authentifiées) */
    private function dataUri(?string $path): ?string
    {
        $disk = Storage::disk('local');

        return $path && $disk->exists($path)
            ? 'data:' . $disk->mimeType($path) . ';base64,' . base64_encode($disk->get($path))
            : null;
    }

    private function fileName(string $prefix, string $name, string $suffix): string
    {
        return $prefix . '-' . Str::slug(Str::limit($name, 50, '')) . '-' . $suffix;
    }

    private function csv(string $name, array $header, callable $rows): StreamedResponse
    {
        return response()->streamDownload(function () use ($header, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");   // BOM : accents corrects à l'ouverture dans Excel
            fputcsv($out, $header, ';');
            foreach ($rows() as $row) {
                fputcsv($out, $row, ';');
            }
            fclose($out);
        }, $name . '.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
