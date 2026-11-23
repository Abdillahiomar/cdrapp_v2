{{-- Sélecteur du fond des pages du tirage : sombre, blanc cassé, jaune clair (mémorisé dans ce navigateur) --}}
<div class="tg-theme" role="group" aria-label="Fond de l'écran" wire:ignore>
    <button type="button" data-v="dark" title="Fond sombre" aria-label="Fond sombre" onclick="tgSetTheme('dark')"></button>
    <button type="button" data-v="light" title="Fond blanc cassé" aria-label="Fond blanc cassé" onclick="tgSetTheme('light')"></button>
    <button type="button" data-v="yellow" title="Fond jaune clair" aria-label="Fond jaune clair" onclick="tgSetTheme('yellow')"></button>
</div>
