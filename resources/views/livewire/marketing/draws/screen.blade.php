<?php

use App\Models\Draw;
use App\Support\DrawEngine;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

/**
 * Grand écran pour annoncer les gagnants en public : révélation un par un,
 * numéro masqué (2537****542). Lit le résultat enregistré, ne tire rien.
 */
new #[Layout('layouts.screen')] class extends Component {

    public Draw $draw;

    public function boot(): void
    {
        abort_unless(auth()->user()?->can('draws.view'), 403);
    }

    public function mount(Draw $draw): void
    {
        abort_unless($draw->isDrawn(), 404);
        $this->draw = $draw;
    }

    public function with(): array
    {
        $items = [];
        foreach ($this->draw->prizes()->with('winners')->get() as $prize) {
            foreach ($prize->winners->filter->isEffective() as $w) {
                $items[] = ['prize' => $prize->name, 'msisdn' => DrawEngine::mask($w->msisdn), 'name' => $w->name ?? ''];
            }
        }

        return ['items' => $items];
    }
};
?>
<div style="min-height:100vh;">
<script>
    // Défini avant le démarrage d'Alpine (page chargée en entier, onglet dédié)
    window.drawScreen = (items) => ({
        items, reverse: true, queue: [], shown: 0, current: null, rolling: false, rollText: '',

        init() { this.reset(); },

        reset() {
            this.queue   = this.reverse ? [...this.items].reverse() : [...this.items];
            this.shown   = 0;
            this.current = null;
        },

        next() {
            if (this.rolling || this.shown >= this.queue.length) return;
            this.current = this.queue[this.shown++];
            this.rolling = true;
            const roll  = () => '2537****' + String(Math.floor(Math.random() * 1000)).padStart(3, '0');
            const timer = setInterval(() => this.rollText = roll(), 70);
            setTimeout(() => { clearInterval(timer); this.rolling = false; }, 2500);
        },
    });
</script>
<div x-data="drawScreen(@js($items))" x-on:keydown.window.space.prevent="next()" x-on:keydown.window.right="next()"
     style="min-height:100vh; display:flex; flex-direction:column; padding:28px 40px;">

    <div style="display:flex; justify-content:space-between; align-items:center; gap:16px; flex-wrap:wrap;">
        <div>
            <p style="font-size:13px; color:#FFC72C; letter-spacing:2px; text-transform:uppercase; font-weight:700;">D-Money · Tirage au sort</p>
            <h1 style="font-size:28px; font-weight:800; margin-top:4px;">{{ $draw->name }}</h1>
        </div>
        <div style="display:flex; gap:8px; align-items:center;">
            <label style="font-size:12px; color:rgba(255,255,255,.6);">
                <input type="checkbox" x-model="reverse" x-on:change="reset()" :disabled="shown > 0"> Gros lot en dernier
            </label>
            <button x-on:click="document.documentElement.requestFullscreen?.()" style="background:rgba(255,255,255,.12); color:#fff; border:none; border-radius:8px; padding:8px 14px; font-size:12px; cursor:pointer;">Plein écran</button>
        </div>
    </div>

    {{-- Révélation en cours --}}
    <div style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:center; text-align:center; padding:30px 0;">
        <template x-if="current">
            <div>
                <p style="font-size:26px; color:#FFC72C; font-weight:700; margin-bottom:18px;" x-text="'🎁 ' + current.prize"></p>
                <p style="font-size:88px; font-weight:800; font-family:monospace; letter-spacing:6px; line-height:1;" x-text="rolling ? rollText : current.msisdn"></p>
                <p style="font-size:34px; font-weight:600; margin-top:20px; min-height:44px; transition:opacity .4s;" :style="rolling ? 'opacity:0' : 'opacity:1'" x-text="current.name"></p>
            </div>
        </template>
        <template x-if="!current">
            <p style="font-size:30px; color:rgba(255,255,255,.7);" x-text="queue.length ? 'Appuyez sur Espace pour révéler le premier gagnant' : 'Aucun gagnant'"></p>
        </template>

        <button x-show="shown < queue.length" x-on:click="next()" :disabled="rolling"
                style="margin-top:40px; background:#FFC72C; color:#1B2F6E; border:none; border-radius:12px; padding:14px 34px; font-size:18px; font-weight:800; cursor:pointer;"
                x-text="shown === 0 ? 'Révéler le premier gagnant' : 'Gagnant suivant (' + shown + ' / ' + queue.length + ')'"></button>
        <p x-show="shown === queue.length && queue.length" style="margin-top:40px; font-size:18px; color:#FFC72C; font-weight:700;">🎉 Félicitations à tous les gagnants !</p>
    </div>

    {{-- Gagnants déjà révélés --}}
    <div x-show="shown > 1" style="border-top:1px solid rgba(255,255,255,.15); padding-top:16px; max-height:32vh; overflow-y:auto;">
        <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(260px, 1fr)); gap:10px;">
            <template x-for="(w, i) in queue.slice(0, shown - 1).reverse()" :key="i">
                <div style="background:rgba(255,255,255,.08); border-radius:10px; padding:10px 14px;">
                    <p style="font-size:11px; color:#FFC72C; font-weight:700;" x-text="w.prize"></p>
                    <p style="font-size:18px; font-family:monospace; font-weight:700; margin-top:2px;" x-text="w.msisdn"></p>
                    <p style="font-size:12px; color:rgba(255,255,255,.75);" x-text="w.name"></p>
                </div>
            </template>
        </div>
    </div>
</div>
</div>
