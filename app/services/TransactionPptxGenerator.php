<?php
// app/Services/TransactionPptxGenerator.php

namespace App\Services;

use PhpOffice\PhpPresentation\PhpPresentation;
use PhpOffice\PhpPresentation\IOFactory;
use PhpOffice\PhpPresentation\Style\Color;
use PhpOffice\PhpPresentation\Style\Alignment;
use PhpOffice\PhpPresentation\Shape\RichText;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Bar;
use PhpOffice\PhpPresentation\Shape\Chart\Type\Pie;
use PhpOffice\PhpPresentation\Shape\Chart\Series;

/**
 * Génère les slides du rapport Transactions (slides 15-16 du rapport
 * original) à partir des KPI calculés par TransactionKpiService.
 *
 * Le service KPI est la source unique : les chiffres du PPTX sont
 * identiques à ceux du dashboard.
 *
 * Prérequis composer : phpoffice/phppresentation
 */
class TransactionPptxGenerator
{
    // Palette D-Money
    private const NAVY = '1B2F6E';
    private const GOLD = 'F5A800';
    private const GREEN = '00843D';
    private const GREY = '6B7280';

    public function __construct(private TransactionKpiService $kpi) {}

    /**
     * Génère le fichier PPTX et retourne son chemin absolu temporaire.
     */
    public function generer(string $mois): string
    {
        $synthese    = $this->kpi->synthese($mois);
        $repartition = $this->kpi->repartitionParUseCase($mois);
        $frais       = $this->kpi->fraisParCanal($mois);

        $ppt = new PhpPresentation();
        $ppt->getDocumentProperties()
            ->setCreator('cdrapp')
            ->setTitle("Rapport Transactions D-Money - {$mois}");

        // On retire la slide vide par défaut après avoir créé les nôtres
        $this->slideTitre($ppt, $mois);
        $this->slideFrais($ppt, $mois, $frais);
        $this->slideRepartition($ppt, $mois, $repartition);
        $ppt->removeSlideByIndex(0); // supprime la slide vide initiale

        $path = storage_path("app/rapport_transactions_{$mois}_" . now()->format('His') . '.pptx');
        IOFactory::createWriter($ppt, 'PowerPoint2007')->save($path);
        return $path;
    }

    /** Slide de titre. */
    private function slideTitre(PhpPresentation $ppt, string $mois): void
    {
        $slide = $ppt->createSlide();
        $shape = $slide->createRichTextShape()->setHeight(120)->setWidth(800)->setOffsetX(80)->setOffsetY(180);
        $shape->getActiveParagraph()->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $run = $shape->createTextRun("Rapport d'activité — Transactions");
        $run->getFont()->setBold(true)->setSize(32)->setColor(new Color('FF' . self::NAVY));
        $p = $shape->createParagraph();
        $p->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $run2 = $p->createTextRun($mois);
        $run2->getFont()->setSize(20)->setColor(new Color('FF' . self::GOLD));
    }

    /** Slide frais par canal (slide 15). */
    private function slideFrais(PhpPresentation $ppt, string $mois, array $frais): void
    {
        $slide = $ppt->createSlide();
        $this->titreSlide($slide, "Frais récoltés par canal — {$mois}");

        // Graphique camembert frais airtime vs hors airtime
        $pie = new Pie();
        $series = new Series('Frais', [
            'Hors Airtime' => $frais['frais_hors_airtime'],
            'Airtime'      => $frais['frais_airtime'],
        ]);
        $series->setShowValue(true);
        $pie->addSeries($series);

        $chart = $slide->createChartShape()
            ->setHeight(320)->setWidth(500)->setOffsetX(120)->setOffsetY(140);
        $chart->getTitle()->setText('Répartition des frais (FDJ)');
        $chart->getPlotArea()->setType($pie);

        // Encadré total
        $box = $slide->createRichTextShape()->setHeight(200)->setWidth(300)->setOffsetX(650)->setOffsetY(160);
        $t = $box->createTextRun('Total frais : ');
        $t->getFont()->setBold(true)->setSize(16)->setColor(new Color('FF' . self::NAVY));
        $t2 = $box->createTextRun(number_format($frais['total_frais'], 0, ',', ' ') . ' FDJ');
        $t2->getFont()->setSize(16)->setColor(new Color('FF' . self::GREEN));
    }

    /** Slide répartition par use-case (slide 16), en volume. */
    private function slideRepartition(PhpPresentation $ppt, string $mois, array $repartition): void
    {
        $slide = $ppt->createSlide();
        $this->titreSlide($slide, "Répartition par use-case (volume) — {$mois}");

        $data = [];
        foreach ($repartition as $r) {
            $data[$r['use_case']] = $r['volume'];
        }

        $bar = new Bar();
        $series = new Series('Volume', $data);
        $series->setShowValue(true);
        $series->getFont()->setSize(9);
        $bar->addSeries($series);
        $bar->setBarDirection(Bar::DIRECTION_HORIZONTAL);

        $chart = $slide->createChartShape()
            ->setHeight(400)->setWidth(820)->setOffsetX(70)->setOffsetY(120);
        $chart->getTitle()->setText('Volume par use-case');
        $chart->getPlotArea()->setType($bar);
    }

    /** Titre standard en haut d'une slide. */
    private function titreSlide($slide, string $texte): void
    {
        $shape = $slide->createRichTextShape()->setHeight(50)->setWidth(860)->setOffsetX(40)->setOffsetY(30);
        $run = $shape->createTextRun($texte);
        $run->getFont()->setBold(true)->setSize(20)->setColor(new Color('FF' . self::NAVY));
    }
}