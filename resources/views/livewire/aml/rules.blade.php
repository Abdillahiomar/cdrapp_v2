<?php

use App\Models\AmlRule;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Volt\Component;

new class extends Component {

    public bool  $showForm = false;
    public ?int  $editingId = null;
    public array $form = [];

    /**
     * ['t' . txn_index => '' | in | out | both] — clé préfixée : une clé
     * numérique (24020) ferait créer un tableau JS creux côté Livewire.
     */
    public array $flowDirections = [];

    /** Activités sélectionnées (population risk_sector) */
    public array $sectors = [];

    public function mount(): void
    {
        $this->form = $this->emptyForm();
    }

    private function emptyForm(): array
    {
        return [
            'code'             => '',
            'name'             => '',
            'description'      => '',
            'population'       => 'rds',
            'period'           => 'day',
            'count_threshold'  => '',
            'amount_threshold' => '',
            'logic'            => 'or',
            'comparison'       => 'gt',
            'severity'         => 'medium',
            'enabled'          => true,
        ];
    }

    public function create(): void
    {
        $this->authorizeManage();

        $this->editingId      = null;
        $this->form           = $this->emptyForm();
        $this->flowDirections = $this->blankFlows();
        $this->sectors        = [];
        $this->showForm       = true;
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();

        $rule = AmlRule::findOrFail($id);

        $this->editingId = $rule->id;
        $this->form      = [
            'code'             => $rule->code,
            'name'             => $rule->name,
            'description'      => $rule->description ?? '',
            'population'       => $rule->population,
            'period'           => $rule->period,
            'count_threshold'  => $rule->count_threshold ?? '',
            'amount_threshold' => $rule->amount_threshold !== null ? (string) (int) $rule->amount_threshold : '',
            'logic'            => $rule->logic,
            'comparison'       => $rule->comparison,
            'severity'         => $rule->severity,
            'enabled'          => $rule->enabled,
        ];
        $this->flowDirections = array_merge(
            $this->blankFlows(),
            collect($rule->flows ?? [])->mapWithKeys(fn ($f) => ['t' . $f['txn_index'] => $f['direction']])->all()
        );
        $this->sectors        = $rule->sectors ?? [];
        $this->showForm       = true;
        $this->resetErrorBag();
    }

    /**
     * Une entrée vide par type de transaction : la propriété est alors un
     * objet côté JS dès le départ, et chaque select a sa clé.
     */
    private function blankFlows(): array
    {
        return DB::table('transaction_types')->pluck('txn_index')
            ->mapWithKeys(fn ($idx) => ['t' . $idx => ''])
            ->all();
    }

    public function cancel(): void
    {
        $this->showForm = false;
        $this->resetErrorBag();
    }

    public function save(): void
    {
        $this->authorizeManage();

        $this->validate([
            'form.code'             => ['required', 'string', 'max:20', Rule::unique('aml_rules', 'code')->ignore($this->editingId)],
            'form.name'             => 'required|string|max:255',
            'form.description'      => 'nullable|string',
            'form.population'       => 'required|in:' . implode(',', array_keys(AmlRule::POPULATIONS)),
            'form.period'           => 'required|in:' . implode(',', array_keys(AmlRule::PERIODS)),
            'form.count_threshold'  => 'nullable|integer|min:0|required_without:form.amount_threshold',
            'form.amount_threshold' => 'nullable|numeric|min:0|required_without:form.count_threshold',
            'form.logic'            => 'required|in:or,and',
            'form.comparison'       => 'required|in:gt,gte',
            'form.severity'         => 'required|in:' . implode(',', array_keys(AmlRule::SEVERITIES)),
        ], [
            'form.count_threshold.required_without'  => 'Renseignez au moins un seuil (nombre ou montant).',
            'form.amount_threshold.required_without' => 'Renseignez au moins un seuil (nombre ou montant).',
            'form.code.unique'                       => 'Ce code de règle existe déjà.',
        ]);

        if ($this->form['population'] === 'risk_sector' && empty($this->sectors)) {
            $this->addError('sectors', 'Sélectionnez au moins un secteur à risque.');
            return;
        }

        $flows = collect($this->flowDirections)
            ->filter(fn ($d) => in_array($d, ['in', 'out', 'both']))
            ->map(fn ($d, $key) => ['txn_index' => (int) ltrim($key, 't'), 'direction' => $d])
            ->values()
            ->all();

        $data = [
            'code'             => strtoupper(trim($this->form['code'])),
            'name'             => $this->form['name'],
            'description'      => $this->form['description'] ?: null,
            'population'       => $this->form['population'],
            'period'           => $this->form['period'],
            'flows'            => $flows,
            'sectors'          => $this->form['population'] === 'risk_sector' ? array_values($this->sectors) : null,
            'count_threshold'  => $this->form['count_threshold'] === '' ? null : (int) $this->form['count_threshold'],
            'amount_threshold' => $this->form['amount_threshold'] === '' ? null : $this->form['amount_threshold'],
            'logic'            => $this->form['logic'],
            'comparison'       => $this->form['comparison'],
            'severity'         => $this->form['severity'],
            'enabled'          => (bool) $this->form['enabled'],
        ];

        if ($this->editingId) {
            unset($data['code']); // le code identifie la règle dans les alertes existantes
            AmlRule::findOrFail($this->editingId)->update($data);
            session()->flash('rule-message', 'Règle mise à jour. Les nouveaux seuils s\'appliqueront à la prochaine détection.');
        } else {
            AmlRule::create($data);
            session()->flash('rule-message', 'Règle créée.');
        }

        $this->showForm = false;
    }

    public function toggle(int $id): void
    {
        $this->authorizeManage();

        $rule = AmlRule::findOrFail($id);
        $rule->update(['enabled' => !$rule->enabled]);
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can('aml.rules.manage'), 403);
    }

    public function with(): array
    {
        $types = DB::table('transaction_types')->orderBy('txn_type_name')->get(['txn_index', 'txn_type_name']);

        return [
            'rules'      => AmlRule::orderBy('id')->get(),
            'types'      => $types,
            'typeNames'  => $types->pluck('txn_type_name', 'txn_index'),
            // Liste des activités des organisations, change rarement. Mise en cache en
            // tableau : le cache ne restaure pas les objets (cache.serializable_classes = false)
            'activities' => $this->showForm
                ? Cache::remember('aml_org_activity_list', 3600, fn () => DB::table('kyc.kyc_organizations')
                    ->whereNotNull('activity')->where('activity', '<>', '')
                    ->distinct()->orderBy('activity')->pluck('activity')->all())
                : [],
        ];
    }
};
?>
<div style="padding:24px;">

@php
    $inputStyle = 'border:1px solid #d1d5db; border-radius:7px; padding:8px 10px; font-size:13px; color:#111827; outline:none; background:#fff; width:100%; box-sizing:border-box;';
    $labelStyle = 'font-size:11px; color:#6b7280; display:block; margin-bottom:4px;';
    $severityStyles = [
        'high'   => ['#FDE8E8', '#7F1D1D'],
        'medium' => ['#FEF3C7', '#92400E'],
        'low'    => ['#E5F5ED', '#005C2B'],
    ];
    $canManage = auth()->user()->can('aml.rules.manage');
@endphp

    <div style="margin-bottom:16px; display:flex; justify-content:space-between; align-items:flex-start; gap:12px; flex-wrap:wrap;">
        <div>
            <h2 style="font-size:16px; font-weight:700; color:#111827; margin:0 0 4px;">AML — Règles</h2>
            <p style="font-size:12px; color:#9ca3af; margin:0;">Seuils appliqués chaque nuit. Une modification s'applique à la détection suivante ; les alertes déjà créées gardent leurs seuils d'origine.</p>
        </div>
        @if($canManage)
            <button wire:click="create"
                    style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:none; cursor:pointer;">
                + Nouvelle règle
            </button>
        @endif
    </div>

    <x-aml-tabs active="aml.rules" />

    @if(session('rule-message'))
        <div style="background:#E5F5ED; color:#005C2B; border:1px solid #A7E3C1; border-radius:8px; padding:10px 14px; font-size:12px; margin-bottom:16px;">
            {{ session('rule-message') }}
        </div>
    @endif

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; overflow:hidden;">
        <div style="overflow-x:auto;">
            <table style="width:100%; border-collapse:collapse; font-size:12px;">
                <thead>
                    <tr style="background:#F7F8FC;">
                        @foreach(['Code', 'Règle', 'Population', 'Période', 'Flux', 'Seuil', 'Sévérité', 'Active', ''] as $th)
                            <th style="padding:10px 12px; text-align:left; color:#6b7280; font-weight:500; border-bottom:1px solid #e5e7eb; white-space:nowrap;">{{ $th }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach($rules as $rule)
                        @php
                            [$sevBg, $sevColor] = $severityStyles[$rule->severity] ?? ['#F3F4F6', '#374151'];
                            $flowLabels = collect($rule->flows ?? [])->map(fn ($f) =>
                                ($typeNames[$f['txn_index']] ?? $f['txn_index']) . ' (' . strtolower(\App\Models\AmlRule::DIRECTIONS[$f['direction']] ?? $f['direction']) . ')'
                            );
                        @endphp
                        <tr wire:key="rule-{{ $rule->id }}" style="border-bottom:1px solid #f3f4f6; {{ $rule->enabled ? '' : 'opacity:.55;' }}">
                            <td style="padding:10px 12px; color:#1B2F6E; font-weight:700;">{{ $rule->code }}</td>
                            <td style="padding:10px 12px; color:#111827; max-width:260px;">
                                {{ $rule->name }}
                                @if($rule->description)
                                    <br><span style="color:#9ca3af; font-size:11px;">{{ $rule->description }}</span>
                                @endif
                            </td>
                            <td style="padding:10px 12px; color:#374151; white-space:nowrap;">
                                {{ \App\Models\AmlRule::POPULATIONS[$rule->population] ?? $rule->population }}
                                @if($rule->population === 'risk_sector')
                                    <br><span style="color:#9ca3af; font-size:11px;">{{ count($rule->sectors ?? []) }} secteur(s)</span>
                                @endif
                            </td>
                            <td style="padding:10px 12px; color:#374151; white-space:nowrap;">{{ \App\Models\AmlRule::PERIODS[$rule->period] ?? $rule->period }}</td>
                            <td style="padding:10px 12px; color:#6b7280; font-size:11px; max-width:240px;" title="{{ $flowLabels->implode(', ') }}">
                                {{ $flowLabels->isEmpty() ? 'Toutes les transactions' : \Illuminate\Support\Str::limit($flowLabels->implode(', '), 90) }}
                            </td>
                            <td style="padding:10px 12px; color:#111827; font-size:11px;">{{ $rule->thresholdLabel() }}</td>
                            <td style="padding:10px 12px;">
                                <span style="background:{{ $sevBg }}; color:{{ $sevColor }}; font-size:11px; font-weight:600; padding:3px 10px; border-radius:20px;">{{ \App\Models\AmlRule::SEVERITIES[$rule->severity] ?? $rule->severity }}</span>
                            </td>
                            <td style="padding:10px 12px;">
                                @if($canManage)
                                    <button wire:click="toggle({{ $rule->id }})"
                                            title="{{ $rule->enabled ? 'Désactiver' : 'Activer' }}"
                                            style="width:36px; height:20px; border-radius:10px; border:none; cursor:pointer; position:relative; background:{{ $rule->enabled ? '#00843D' : '#d1d5db' }};">
                                        <span style="position:absolute; top:2px; {{ $rule->enabled ? 'right:2px' : 'left:2px' }}; width:16px; height:16px; border-radius:50%; background:#fff;"></span>
                                    </button>
                                @else
                                    {{ $rule->enabled ? 'Oui' : 'Non' }}
                                @endif
                            </td>
                            <td style="padding:10px 12px;">
                                @if($canManage)
                                    <button wire:click="edit({{ $rule->id }})"
                                            style="background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:5px 10px; border-radius:6px; border:none; cursor:pointer;">
                                        Modifier
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- FORMULAIRE --}}
    @if($showForm)
        <div style="position:fixed; inset:0; background:rgba(17,24,39,0.45); z-index:200; display:flex; justify-content:flex-end;"
             wire:click.self="cancel">
            <div style="width:min(760px, 100%); height:100%; background:#fff; overflow-y:auto; box-shadow:-8px 0 24px rgba(0,0,0,0.15);">
                <div style="padding:18px 24px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:center; position:sticky; top:0; background:#fff; z-index:1;">
                    <p style="font-size:15px; font-weight:700; color:#111827; margin:0;">{{ $editingId ? 'Modifier la règle ' . $form['code'] : 'Nouvelle règle' }}</p>
                    <button wire:click="cancel" style="background:none; border:none; font-size:22px; color:#9ca3af; cursor:pointer; line-height:1;">×</button>
                </div>

                <div style="padding:20px 24px; display:grid; grid-template-columns:repeat(2, minmax(0,1fr)); gap:14px;">
                    <div>
                        <label style="{{ $labelStyle }}">Code</label>
                        <input type="text" wire:model="form.code" placeholder="AL19" @disabled($editingId) style="{{ $inputStyle }} {{ $editingId ? 'background:#f3f4f6;' : '' }}">
                        @error('form.code') <p style="font-size:11px; color:#B91C1C; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}">Sévérité</label>
                        <select wire:model="form.severity" style="{{ $inputStyle }}">
                            @foreach(\App\Models\AmlRule::SEVERITIES as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="{{ $labelStyle }}">Libellé</label>
                        <input type="text" wire:model="form.name" style="{{ $inputStyle }}">
                        @error('form.name') <p style="font-size:11px; color:#B91C1C; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="{{ $labelStyle }}">Description / remarques conformité</label>
                        <textarea wire:model="form.description" rows="2" style="{{ $inputStyle }} resize:vertical;"></textarea>
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}">Population</label>
                        <select wire:model.live="form.population" style="{{ $inputStyle }}">
                            @foreach(\App\Models\AmlRule::POPULATIONS as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}">Période</label>
                        <select wire:model="form.period" style="{{ $inputStyle }}">
                            @foreach(\App\Models\AmlRule::PERIODS as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </div>

                    <div>
                        <label style="{{ $labelStyle }}">Seuil nombre de transactions</label>
                        <input type="number" min="0" wire:model="form.count_threshold" placeholder="vide = pas de seuil" style="{{ $inputStyle }}">
                        @error('form.count_threshold') <p style="font-size:11px; color:#B91C1C; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}">Seuil montant cumulé (FDJ)</label>
                        <input type="number" min="0" wire:model="form.amount_threshold" placeholder="vide = pas de seuil" style="{{ $inputStyle }}">
                        @error('form.amount_threshold') <p style="font-size:11px; color:#B91C1C; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}">Combinaison des seuils</label>
                        <select wire:model="form.logic" style="{{ $inputStyle }}">
                            <option value="or">OU — un seul seuil dépassé suffit</option>
                            <option value="and">ET — les deux seuils doivent être dépassés</option>
                        </select>
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}">Comparaison</label>
                        <select wire:model="form.comparison" style="{{ $inputStyle }}">
                            <option value="gt">Strictement supérieur (&gt;)</option>
                            <option value="gte">Supérieur ou égal (≥, « atteignant »)</option>
                        </select>
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#374151; cursor:pointer;">
                            <input type="checkbox" wire:model="form.enabled"> Règle active
                        </label>
                    </div>

                    @if($form['population'] === 'risk_sector')
                        <div style="grid-column:1 / -1;">
                            <label style="{{ $labelStyle }}">Secteurs à risque (activité KYC de l'organisation)</label>
                            <div style="border:1px solid #d1d5db; border-radius:7px; max-height:200px; overflow-y:auto; padding:8px 10px;">
                                @foreach($activities as $activity)
                                    <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#374151; padding:3px 0; cursor:pointer;">
                                        <input type="checkbox" wire:model="sectors" value="{{ $activity }}"> {{ $activity }}
                                    </label>
                                @endforeach
                            </div>
                            @error('sectors') <p style="font-size:11px; color:#B91C1C; margin:4px 0 0;">{{ $message }}</p> @enderror
                        </div>
                    @endif

                    <div style="grid-column:1 / -1;">
                        <label style="{{ $labelStyle }}">Flux concernés — aucun sélectionné = toutes les transactions du compte</label>
                        <p style="font-size:11px; color:#9ca3af; margin:0 0 6px;">Entrant = le compte est crédité (cash in reçu, transfert reçu…). Sortant = le compte est débité (cash out, transfert envoyé…).</p>
                        <div style="border:1px solid #d1d5db; border-radius:7px; max-height:320px; overflow-y:auto;">
                            @foreach($types as $type)
                                <div wire:key="flow-{{ $type->txn_index }}"
                                     style="display:flex; align-items:center; justify-content:space-between; gap:10px; padding:5px 10px; border-bottom:1px solid #f3f4f6; {{ !empty($flowDirections['t' . $type->txn_index] ?? '') ? 'background:#F0F4FF;' : '' }}">
                                    <span style="font-size:12px; color:#111827;">{{ $type->txn_type_name }}</span>
                                    <select wire:model.live="flowDirections.t{{ $type->txn_index }}"
                                            style="border:1px solid #d1d5db; border-radius:6px; padding:3px 6px; font-size:11px; background:#fff; width:110px;">
                                        <option value="">—</option>
                                        @foreach(\App\Models\AmlRule::DIRECTIONS as $key => $label)
                                            <option value="{{ $key }}">{{ $label }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>

                <div style="padding:16px 24px; border-top:1px solid #e5e7eb; display:flex; gap:8px; justify-content:flex-end; position:sticky; bottom:0; background:#fff;">
                    <button wire:click="cancel"
                            style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:1px solid #d1d5db; cursor:pointer;">
                        Annuler
                    </button>
                    <button wire:click="save"
                            style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:none; cursor:pointer;">
                        Enregistrer
                    </button>
                </div>
            </div>
        </div>
    @endif

</div>
