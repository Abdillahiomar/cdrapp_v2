<?php

namespace App\Support;

use Random\Randomizer;

/**
 * Urne pondérée pour le tirage au sort : chaque participant a un poids (nombre
 * de chances). Tirage et retrait en O(log n) grâce à un arbre de Fenwick, ce qui
 * reste rapide avec plusieurs centaines de milliers de participants.
 */
class WeightedPool
{
    private array $tree;
    private array $weights;
    private array $current = [];   // poids modifiés (retraits), par indice
    private int $size;
    private int $total = 0;

    /** @param int[] $weights poids par participant, indices 0..n-1 */
    public function __construct(array $weights)
    {
        $this->weights = array_values(array_map('intval', $weights));
        $this->size    = count($this->weights);
        $this->tree    = array_fill(0, $this->size + 1, 0);

        // Construction en O(n)
        for ($i = 1; $i <= $this->size; $i++) {
            $this->tree[$i] += $this->weights[$i - 1];
            $this->total    += $this->weights[$i - 1];
            $parent = $i + ($i & -$i);
            if ($parent <= $this->size) {
                $this->tree[$parent] += $this->tree[$i];
            }
        }
    }

    public function total(): int
    {
        return $this->total;
    }

    /** Indice tiré proportionnellement aux poids, null si l'urne est vide */
    public function pick(Randomizer $randomizer): ?int
    {
        if ($this->total <= 0) {
            return null;
        }

        $remaining = $randomizer->getInt(1, $this->total);
        $pos       = 0;

        for ($step = 1 << (int) floor(log(max(1, $this->size), 2)); $step > 0; $step >>= 1) {
            if ($pos + $step <= $this->size && $this->tree[$pos + $step] < $remaining) {
                $pos       += $step;
                $remaining -= $this->tree[$pos];
            }
        }

        return $pos;
    }

    /** Retire un participant de l'urne (il ne peut plus sortir) */
    public function remove(int $index): void
    {
        $this->add($index, -$this->current($index));
    }

    /** Remet un participant retiré avec son poids d'origine */
    public function restore(int $index, int $weight): void
    {
        $this->add($index, $weight - $this->current($index));
    }

    private function current(int $index): int
    {
        return $this->current[$index] ?? $this->weights[$index];
    }

    private function add(int $index, int $delta): void
    {
        if ($delta === 0) {
            return;
        }
        $this->current[$index] = $this->current($index) + $delta;
        $this->total += $delta;
        for ($i = $index + 1; $i <= $this->size; $i += $i & -$i) {
            $this->tree[$i] += $delta;
        }
    }
}
