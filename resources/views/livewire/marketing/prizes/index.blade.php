<?php

use App\Models\Prize;
use App\Models\PrizeCategory;
use App\Models\PrizeImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Volt\Component;
use Livewire\WithFileUploads;

/**
 * Catalogue des lots Marketing, enregistrés à l'avance et regroupés par
 * catégorie (Platinum, Gold, Bronze…). Chaque lot a au moins une image ; la
 * première est l'image principale. Consultation : draws.view ; gestion : draws.manage.
 */
new class extends Component {
    use WithFileUploads;

    public string $search       = '';
    public string $filterCat    = '';
    public bool   $showArchived = false;

    // Formulaire lot
    public bool  $showForm      = false;
    public ?int  $editingId     = null;
    public array $form          = ['prize_category_id' => '', 'name' => '', 'description' => '', 'value' => '', 'is_active' => true];
    public array $existing      = [];   // [{id, url, remove}]
    public array $uploads       = [];

    // Catégories
    public bool  $showCategories = false;
    public array $categories     = [];
    public array $newCategory    = ['name' => '', 'color' => '#1B2F6E'];

    private const DISK = 'local';

    /** Le middleware de route ne couvre pas les appels Livewire suivants */
    public function boot(): void
    {
        abort_unless(auth()->user()?->can('draws.view'), 403);
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->can('draws.manage'), 403);
    }

    // ── Lots ──────────────────────────────────────────────────────────────

    public function create(): void
    {
        $this->authorizeManage();
        $this->editingId = null;
        $this->form      = ['prize_category_id' => (string) (PrizeCategory::orderBy('position')->value('id') ?? ''), 'name' => '', 'description' => '', 'value' => '', 'is_active' => true];
        $this->existing  = [];
        $this->uploads   = [];
        $this->showForm  = true;
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $this->authorizeManage();
        $prize           = Prize::with('images')->findOrFail($id);
        $this->editingId = $prize->id;
        $this->form      = [
            'prize_category_id' => (string) $prize->prize_category_id,
            'name'              => $prize->name,
            'description'       => (string) $prize->description,
            'value'             => $prize->value !== null ? (string) (float) $prize->value : '',
            'is_active'         => $prize->is_active,
        ];
        $this->existing  = $prize->images->map(fn ($img) => ['id' => $img->id, 'url' => $img->url(), 'remove' => false])->all();
        $this->uploads   = [];
        $this->showForm  = true;
        $this->resetErrorBag();
    }

    /** Place une image existante en tête : elle devient l'image principale */
    public function makeMain(int $index): void
    {
        if (isset($this->existing[$index])) {
            $img = $this->existing[$index];
            unset($this->existing[$index]);
            $this->existing = array_values([$img, ...$this->existing]);
        }
    }

    public function removeUpload(int $index): void
    {
        unset($this->uploads[$index]);
        $this->uploads = array_values($this->uploads);
    }

    public function save(): void
    {
        $this->authorizeManage();

        $this->validate([
            'form.prize_category_id' => 'required|exists:prize_categories,id',
            'form.name'              => 'required|string|max:255',
            'form.description'       => 'nullable|string|max:2000',
            'form.value'             => 'nullable|numeric|min:0',
            'uploads.*'              => 'image|mimes:jpg,jpeg,png,webp|max:2048',
        ], [
            'form.prize_category_id.required' => 'Choisissez une catégorie.',
            'form.name.required'              => 'Le nom du lot est obligatoire.',
            'uploads.*.image'                 => 'Le fichier doit être une image.',
            'uploads.*.mimes'                 => 'Formats acceptés : jpg, png, webp.',
            'uploads.*.max'                   => 'Image trop lourde (2 Mo maximum).',
        ]);

        $kept = array_values(array_filter($this->existing, fn ($img) => !$img['remove']));
        if (count($kept) + count($this->uploads) === 0) {
            $this->addError('uploads', 'Chaque lot doit avoir au moins une image.');
            return;
        }

        $data = [
            'prize_category_id' => (int) $this->form['prize_category_id'],
            'name'              => trim($this->form['name']),
            'description'       => trim($this->form['description']) ?: null,
            'value'             => $this->form['value'] !== '' ? (float) $this->form['value'] : null,
            'is_active'         => (bool) $this->form['is_active'],
        ];

        $toDelete = [];

        DB::transaction(function () use ($data, $kept, &$toDelete) {
            $prize = $this->editingId ? tap(Prize::findOrFail($this->editingId))->update($data) : Prize::create($data);

            foreach ($this->existing as $img) {
                if ($img['remove'] && ($row = PrizeImage::where('prize_id', $prize->id)->find($img['id']))) {
                    $toDelete[] = $row->path;
                    $row->delete();
                }
            }

            // Ordre : images conservées (dans l'ordre affiché), puis nouvelles
            $position = 0;
            foreach ($kept as $img) {
                PrizeImage::where('prize_id', $prize->id)->whereKey($img['id'])->update(['position' => $position++]);
            }
            foreach ($this->uploads as $file) {
                $prize->images()->create(['path' => $file->store('prizes', self::DISK), 'position' => $position++]);
            }
        });

        Storage::disk(self::DISK)->delete($toDelete);

        session()->flash('success', $this->editingId ? "Lot « {$data['name']} » mis à jour." : "Lot « {$data['name']} » ajouté au catalogue.");
        $this->showForm = false;
        $this->uploads  = [];
    }

    public function toggleActive(int $id): void
    {
        $this->authorizeManage();
        $prize = Prize::findOrFail($id);
        $prize->update(['is_active' => !$prize->is_active]);
    }

    public function delete(int $id): void
    {
        $this->authorizeManage();
        $prize = Prize::with('images')->findOrFail($id);

        if ($prize->isUsed()) {
            session()->flash('error', 'Ce lot a déjà servi dans un tirage : archivez-le plutôt que de le supprimer.');
            return;
        }

        $paths = $prize->images->pluck('path')->all();
        $prize->delete();
        Storage::disk(self::DISK)->delete($paths);
        session()->flash('success', 'Lot supprimé.');
    }

    // ── Catégories ────────────────────────────────────────────────────────

    public function openCategories(): void
    {
        $this->authorizeManage();
        $this->categories     = PrizeCategory::withCount('prizes')->orderBy('position')->orderBy('id')->get()
            ->map(fn ($c) => ['id' => $c->id, 'name' => $c->name, 'color' => $c->color, 'prizes_count' => $c->prizes_count])->all();
        $this->newCategory    = ['name' => '', 'color' => '#1B2F6E'];
        $this->showCategories = true;
        $this->resetErrorBag();
    }

    public function saveCategories(): void
    {
        $this->authorizeManage();

        $this->validate([
            'categories.*.name'  => 'required|string|max:100|distinct',
            'categories.*.color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'categories.*.name.required' => 'Nom obligatoire.',
            'categories.*.name.distinct' => 'Nom en double.',
            'categories.*.color.regex'   => 'Couleur invalide.',
        ]);

        foreach ($this->categories as $i => $c) {
            PrizeCategory::whereKey($c['id'])->update(['name' => trim($c['name']), 'color' => $c['color'], 'position' => $i + 1]);
        }

        $this->showCategories = false;
        session()->flash('success', 'Catégories enregistrées.');
    }

    public function addCategory(): void
    {
        $this->authorizeManage();

        $this->validate([
            'newCategory.name'  => 'required|string|max:100|unique:prize_categories,name',
            'newCategory.color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ], [
            'newCategory.name.required' => 'Nom obligatoire.',
            'newCategory.name.unique'   => 'Cette catégorie existe déjà.',
        ]);

        PrizeCategory::create([
            'name'     => trim($this->newCategory['name']),
            'color'    => $this->newCategory['color'],
            'position' => (int) PrizeCategory::max('position') + 1,
        ]);

        $this->openCategories();
    }

    public function moveCategory(int $index, int $direction): void
    {
        $to = $index + $direction;
        if (isset($this->categories[$index], $this->categories[$to])) {
            [$this->categories[$index], $this->categories[$to]] = [$this->categories[$to], $this->categories[$index]];
        }
    }

    public function deleteCategory(int $id): void
    {
        $this->authorizeManage();

        if (Prize::where('prize_category_id', $id)->exists()) {
            $this->addError('categories', 'Cette catégorie contient des lots : déplacez-les avant de la supprimer.');
            return;
        }

        PrizeCategory::whereKey($id)->delete();
        $this->openCategories();
    }

    public function with(): array
    {
        $prizes = Prize::with(['category', 'mainImage'])
            ->withCount('images')
            ->when(!$this->showArchived, fn ($q) => $q->where('is_active', true))
            ->when($this->filterCat, fn ($q) => $q->where('prize_category_id', $this->filterCat))
            ->when($this->search, fn ($q) => $q->where('name', 'ilike', '%' . $this->search . '%'))
            ->orderBy('name')
            ->get();

        $categoryList = PrizeCategory::orderBy('position')->orderBy('id')->get();

        return [
            'groups'       => $categoryList->map(fn ($c) => ['category' => $c, 'prizes' => $prizes->where('prize_category_id', $c->id)->values()])
                                           ->filter(fn ($g) => $g['prizes']->isNotEmpty()),
            'categoryList' => $categoryList,
            'total'        => Prize::where('is_active', true)->count(),
            'archived'     => Prize::where('is_active', false)->count(),
            'usedIds'      => DB::table('draw_prizes')->whereNotNull('prize_id')->distinct()->pluck('prize_id')->flip(),
            'canManage'    => auth()->user()->can('draws.manage'),
        ];
    }
};
?>
<div style="padding:24px;">

@php
    $inputStyle = 'border:1px solid #d1d5db; border-radius:7px; padding:9px 12px; font-size:13px; color:#111827; outline:none; background:#fff; width:100%; box-sizing:border-box;';
    $labelStyle = 'font-size:11px; color:#6b7280; display:block; margin-bottom:4px; font-weight:600;';
    $btnLight   = 'background:#E8ECF8; color:#1B2F6E; font-size:11px; font-weight:600; padding:5px 10px; border-radius:6px; border:none; cursor:pointer;';
    $btnDanger  = 'background:#FDECEC; color:#B42318; font-size:11px; font-weight:600; padding:5px 10px; border-radius:6px; border:none; cursor:pointer;';
@endphp

    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:20px; gap:12px; flex-wrap:wrap;">
        <div>
            <a href="{{ route('draws.index') }}" wire:navigate style="font-size:12px; color:#6b7280; text-decoration:none;">← Tirages au sort</a>
            <h2 style="font-size:16px; font-weight:700; color:#111827; margin:6px 0 4px;">Catalogue des lots</h2>
            <p style="font-size:12px; color:#9ca3af; margin:0;">{{ $total }} lot(s) disponible(s){{ $archived ? ", {$archived} archivé(s)" : '' }} — à sélectionner ensuite dans chaque tirage.</p>
        </div>
        @if($canManage)
            <div style="display:flex; gap:8px;">
                <button wire:click="openCategories" style="background:#E8ECF8; color:#1B2F6E; font-size:13px; font-weight:600; padding:9px 16px; border-radius:8px; border:none; cursor:pointer;">Catégories</button>
                <button wire:click="create" style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:9px 18px; border-radius:8px; border:none; cursor:pointer;">+ Nouveau lot</button>
            </div>
        @endif
    </div>

    @if(session('success'))
        <div style="background:#E5F5ED; color:#005C2B; border:1px solid #a7d7b8; border-radius:8px; padding:12px 16px; margin-bottom:16px; font-size:13px;">{{ session('success') }}</div>
    @endif
    @if(session('error'))
        <div style="background:#FDECEC; color:#B42318; border:1px solid #f5c2c0; border-radius:8px; padding:12px 16px; margin-bottom:16px; font-size:13px;">{{ session('error') }}</div>
    @endif

    <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:16px; margin-bottom:16px; display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end;">
        <div style="flex:1; min-width:200px;">
            <label style="{{ $labelStyle }}">Rechercher</label>
            <input type="text" wire:model.live.debounce.300ms="search" placeholder="Nom du lot…" style="{{ $inputStyle }}">
        </div>
        <div>
            <label style="{{ $labelStyle }}">Catégorie</label>
            <select wire:model.live="filterCat" style="{{ $inputStyle }} width:auto;">
                <option value="">Toutes</option>
                @foreach($categoryList as $c)
                    <option value="{{ $c->id }}">{{ $c->name }}</option>
                @endforeach
            </select>
        </div>
        <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#374151; padding-bottom:9px;">
            <input type="checkbox" wire:model.live="showArchived"> Afficher les lots archivés
        </label>
    </div>

    @forelse($groups as $group)
        <div style="margin-bottom:22px;">
            <p style="font-size:13px; font-weight:700; color:{{ $group['category']->color }}; margin:0 0 10px; display:flex; align-items:center; gap:8px;">
                <span style="width:10px; height:10px; border-radius:50%; background:{{ $group['category']->color }};"></span>
                {{ $group['category']->name }} <span style="font-weight:400; color:#9ca3af;">({{ $group['prizes']->count() }})</span>
            </p>
            <div style="display:grid; grid-template-columns:repeat(auto-fill, minmax(210px, 1fr)); gap:12px;">
                @foreach($group['prizes'] as $prize)
                    <div wire:key="prize-{{ $prize->id }}" style="background:#fff; border:1px solid #e5e7eb; border-top:3px solid {{ $group['category']->color }}; border-radius:10px; overflow:hidden; {{ $prize->is_active ? '' : 'opacity:.55;' }}">
                        <div style="height:150px; background:#F7F8FC; display:flex; align-items:center; justify-content:center;">
                            @if($prize->mainImage)
                                <img src="{{ $prize->mainImage->url() }}" alt="" style="max-width:100%; max-height:150px; object-fit:contain;">
                            @endif
                        </div>
                        <div style="padding:10px 12px;">
                            <p style="font-size:13px; font-weight:700; color:#111827; margin:0;">{{ $prize->name }}</p>
                            <p style="font-size:11px; color:#6b7280; margin:3px 0 0;">
                                {{ $prize->images_count }} image(s)
                                @if($prize->value) · {{ number_format((float) $prize->value, 0, ',', ' ') }} FDJ @endif
                                @unless($prize->is_active) · <strong>archivé</strong> @endunless
                                @if(isset($usedIds[$prize->id])) · utilisé @endif
                            </p>
                            @if($prize->description)
                                <p style="font-size:11px; color:#9ca3af; margin:4px 0 0;">{{ \Illuminate\Support\Str::limit($prize->description, 70) }}</p>
                            @endif
                            @if($canManage)
                                <div style="display:flex; gap:6px; margin-top:10px; flex-wrap:wrap;">
                                    <button wire:click="edit({{ $prize->id }})" style="{{ $btnLight }}">Modifier</button>
                                    <button wire:click="toggleActive({{ $prize->id }})" style="{{ $btnLight }}">{{ $prize->is_active ? 'Archiver' : 'Restaurer' }}</button>
                                    @unless(isset($usedIds[$prize->id]))
                                        <button wire:click="delete({{ $prize->id }})" wire:confirm="Supprimer définitivement ce lot et ses images ?" style="{{ $btnDanger }}">Supprimer</button>
                                    @endunless
                                </div>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        </div>
    @empty
        <div style="background:#fff; border:1px solid #e5e7eb; border-radius:10px; padding:40px; text-align:center; color:#9ca3af; font-size:13px;">Aucun lot dans le catalogue.</div>
    @endforelse

    {{-- MODAL LOT --}}
    @if($showForm)
        <div wire:click="$set('showForm', false)" style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;"></div>
        <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:min(560px,95vw); max-height:92vh; overflow-y:auto; background:#fff; border-radius:14px; z-index:999; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb;">
                <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">{{ $editingId ? 'Modifier le lot' : 'Nouveau lot' }}</p>
            </div>
            <div style="padding:20px; display:flex; flex-direction:column; gap:14px;">
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:12px;">
                    <div>
                        <label style="{{ $labelStyle }}">Catégorie <span style="color:#E24B4A;">*</span></label>
                        <select wire:model="form.prize_category_id" style="{{ $inputStyle }}">
                            <option value="">—</option>
                            @foreach($categoryList as $c)
                                <option value="{{ $c->id }}">{{ $c->name }}</option>
                            @endforeach
                        </select>
                        @error('form.prize_category_id') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label style="{{ $labelStyle }}">Valeur indicative (FDJ)</label>
                        <input type="number" min="0" wire:model="form.value" style="{{ $inputStyle }}">
                    </div>
                </div>
                <div>
                    <label style="{{ $labelStyle }}">Nom du lot <span style="color:#E24B4A;">*</span></label>
                    <input type="text" wire:model="form.name" placeholder="Ex. iPhone 16, Tablette Samsung…" style="{{ $inputStyle }}">
                    @error('form.name') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label style="{{ $labelStyle }}">Description</label>
                    <textarea wire:model="form.description" rows="2" style="{{ $inputStyle }} resize:vertical;"></textarea>
                </div>

                <div>
                    <label style="{{ $labelStyle }}">Images <span style="color:#E24B4A;">*</span> <span style="font-weight:400;">(la première est l'image principale)</span></label>
                    <div style="display:flex; flex-wrap:wrap; gap:8px; margin-bottom:8px;">
                        @php $first = true; @endphp
                        @foreach($existing as $i => $img)
                            <div wire:key="img-{{ $img['id'] }}" style="width:96px; border:1px solid {{ $img['remove'] ? '#f5c2c0' : '#e5e7eb' }}; border-radius:8px; overflow:hidden; {{ $img['remove'] ? 'opacity:.4;' : '' }}">
                                <img src="{{ $img['url'] }}" alt="" style="width:96px; height:72px; object-fit:cover; display:block;">
                                <div style="padding:4px; display:flex; flex-direction:column; gap:3px;">
                                    @if(!$img['remove'] && $first)
                                        <span style="font-size:9px; font-weight:700; color:#005C2B; text-align:center;">PRINCIPALE</span>
                                        @php $first = false; @endphp
                                    @elseif(!$img['remove'])
                                        <button type="button" wire:click="makeMain({{ $i }})" style="font-size:9px; border:none; background:#E8ECF8; color:#1B2F6E; border-radius:4px; cursor:pointer; padding:2px;">Principale</button>
                                    @endif
                                    <label style="font-size:9px; color:#B42318; display:flex; align-items:center; gap:3px; justify-content:center;">
                                        <input type="checkbox" wire:model.live="existing.{{ $i }}.remove"> retirer
                                    </label>
                                </div>
                            </div>
                        @endforeach
                        @foreach($uploads as $i => $file)
                            <div wire:key="up-{{ $i }}" style="width:96px; border:1px dashed #1B2F6E; border-radius:8px; overflow:hidden;">
                                @if(method_exists($file, 'isPreviewable') && $file->isPreviewable())
                                    <img src="{{ $file->temporaryUrl() }}" alt="" style="width:96px; height:72px; object-fit:cover; display:block;">
                                @endif
                                <button type="button" wire:click="removeUpload({{ $i }})" style="width:100%; font-size:9px; border:none; background:#FDECEC; color:#B42318; cursor:pointer; padding:3px;">Retirer</button>
                            </div>
                        @endforeach
                    </div>
                    <input type="file" wire:model="uploads" multiple accept="image/png,image/jpeg,image/webp" style="font-size:12px;">
                    <span wire:loading wire:target="uploads" style="font-size:11px; color:#9ca3af;">Chargement…</span>
                    <p style="font-size:10px; color:#9ca3af; margin:4px 0 0;">jpg, png ou webp, 2 Mo maximum par image.</p>
                    @error('uploads') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                    @error('uploads.*') <p style="font-size:10px; color:#E24B4A; margin:4px 0 0;">{{ $message }}</p> @enderror
                </div>

                <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#374151;">
                    <input type="checkbox" wire:model="form.is_active"> Lot disponible (proposé dans les tirages)
                </label>
            </div>
            <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:10px;">
                <button wire:click="$set('showForm', false)" style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">Annuler</button>
                <button wire:click="save" wire:loading.attr="disabled" style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:8px 20px; border-radius:8px; border:none; cursor:pointer;">Enregistrer</button>
            </div>
        </div>
    @endif

    {{-- MODAL CATÉGORIES --}}
    @if($showCategories)
        <div wire:click="$set('showCategories', false)" style="position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:998;"></div>
        <div style="position:fixed; top:50%; left:50%; transform:translate(-50%,-50%); width:min(500px,95vw); max-height:92vh; overflow-y:auto; background:#fff; border-radius:14px; z-index:999; box-shadow:0 20px 60px rgba(0,0,0,0.2);">
            <div style="padding:16px 20px; border-bottom:1px solid #e5e7eb;">
                <p style="font-size:14px; font-weight:700; color:#111827; margin:0;">Catégories de lots</p>
                <p style="font-size:11px; color:#9ca3af; margin:4px 0 0;">Nom, couleur et ordre d'affichage.</p>
            </div>
            <div style="padding:20px; display:flex; flex-direction:column; gap:8px;">
                @foreach($categories as $i => $c)
                    <div wire:key="cat-{{ $c['id'] }}" style="display:flex; align-items:center; gap:8px;">
                        <button wire:click="moveCategory({{ $i }}, -1)" @disabled($i === 0) style="border:none; background:none; cursor:pointer; color:#1B2F6E;">▲</button>
                        <button wire:click="moveCategory({{ $i }}, 1)" @disabled($i === count($categories) - 1) style="border:none; background:none; cursor:pointer; color:#1B2F6E;">▼</button>
                        <input type="color" wire:model="categories.{{ $i }}.color" style="width:36px; height:32px; border:1px solid #d1d5db; border-radius:6px; padding:2px;">
                        <input type="text" wire:model="categories.{{ $i }}.name" style="{{ $inputStyle }} padding:6px 10px;">
                        <span style="font-size:11px; color:#9ca3af; white-space:nowrap;">{{ $c['prizes_count'] }} lot(s)</span>
                        @if(!$c['prizes_count'])
                            <button wire:click="deleteCategory({{ $c['id'] }})" wire:confirm="Supprimer cette catégorie ?" style="{{ $btnDanger }}">✕</button>
                        @endif
                    </div>
                    @error("categories.$i.name") <p style="font-size:10px; color:#E24B4A; margin:0 0 0 70px;">{{ $message }}</p> @enderror
                @endforeach
                @error('categories') <p style="font-size:11px; color:#E24B4A; margin:0;">{{ $message }}</p> @enderror

                <div style="display:flex; align-items:center; gap:8px; margin-top:10px; padding-top:12px; border-top:1px dashed #e5e7eb;">
                    <input type="color" wire:model="newCategory.color" style="width:36px; height:32px; border:1px solid #d1d5db; border-radius:6px; padding:2px;">
                    <input type="text" wire:model="newCategory.name" placeholder="Nouvelle catégorie (ex. Silver)" style="{{ $inputStyle }} padding:6px 10px;">
                    <button wire:click="addCategory" style="{{ $btnLight }} padding:7px 12px; white-space:nowrap;">+ Ajouter</button>
                </div>
                @error('newCategory.name') <p style="font-size:10px; color:#E24B4A; margin:0;">{{ $message }}</p> @enderror
            </div>
            <div style="padding:12px 20px; border-top:1px solid #e5e7eb; display:flex; justify-content:flex-end; gap:10px;">
                <button wire:click="$set('showCategories', false)" style="background:#f3f4f6; color:#374151; font-size:13px; font-weight:500; padding:8px 20px; border-radius:8px; border:1px solid #e5e7eb; cursor:pointer;">Fermer</button>
                <button wire:click="saveCategories" style="background:#1B2F6E; color:#fff; font-size:13px; font-weight:600; padding:8px 20px; border-radius:8px; border:none; cursor:pointer;">Enregistrer</button>
            </div>
        </div>
    @endif

</div>
