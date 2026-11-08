<?php

namespace App\Http\Controllers;

use App\Models\Draw;
use App\Models\DrawPrize;
use App\Models\DrawWinner;
use App\Models\PrizeImage;
use App\Support\DrawEngine;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Exports d'un tirage au sort : liste des éligibles, gagnants et procès-verbal */
class DrawController extends Controller
{
    /** Liste figée des éligibles (CSV ; séparateur « ; » pour Excel en français) */
    public function eligibles(Draw $draw): StreamedResponse
    {
        abort_if(in_array($draw->status, ['draft', 'computing'], true), 404);

        return $this->csv($this->fileName($draw, 'eligibles'), ['Numéro', 'Nom', 'Transactions', 'Montant (FDJ)', 'Dernière transaction'], function () use ($draw) {
            foreach (DB::table('draw_participants')->where('draw_id', $draw->id)->orderBy('msisdn')->cursor() as $p) {
                yield [$p->msisdn, $p->name, $p->txn_count, $p->amount, $p->last_txn_at];
            }
        });
    }

    /** Toutes les sorties du tirage, y compris suppléants et gagnants écartés */
    public function winners(Draw $draw): StreamedResponse
    {
        abort_unless($draw->isDrawn(), 404);

        $rows = $draw->prizes()->with(['winners.replaces', 'winners.invalidator'])->get()->flatMap(
            fn ($prize) => $prize->winners->map(fn (DrawWinner $w) => [
                $prize->category_name,
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
            ])
        );

        return $this->csv(
            $this->fileName($draw, 'gagnants'),
            ['Catégorie', 'Lot', 'Type', 'Rang', 'Numéro', 'Nom', 'Transactions', 'Gagnant retenu', 'Statut', 'Motif', 'Remplace'],
            fn () => $rows
        );
    }

    /** Procès-verbal du tirage (PDF) */
    public function pv(Draw $draw)
    {
        abort_unless($draw->isDrawn(), 404);

        $draw->load(['creator', 'computer', 'drawer', 'closer']);

        $prizes = $draw->prizes()->with(['winners.replaces', 'winners.invalidator'])->get();

        $pdf = Pdf::loadView('draws.pv', [
            'draw'      => $draw,
            'prizes'    => $prizes,
            'images'    => $prizes->mapWithKeys(fn ($p) => [$p->id => $this->dataUri($p->image_path)]),
            'typeNames' => DB::table('transaction_types')->whereIn('txn_index', $draw->txn_indexes ?? [])->orderBy('txn_type_name')->pluck('txn_type_name'),
            'verify'    => app(DrawEngine::class)->verify($draw),
        ])->setPaper('a4');

        return $pdf->download($this->fileName($draw, 'pv') . '.pdf');
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

    private function fileName(Draw $draw, string $suffix): string
    {
        return 'tirage-' . $draw->id . '-' . Str::slug(Str::limit($draw->name, 40, '')) . '-' . $suffix;
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
