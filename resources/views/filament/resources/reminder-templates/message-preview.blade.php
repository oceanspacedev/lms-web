@once
    <style>
        .whatsapp-settings-group > .fi-section > .fi-section-header .fi-section-header-heading { font-size: 1.125rem; line-height: 1.5; font-weight: 600; }
        .whatsapp-settings-status { border-top: 1px solid var(--gray-200); padding-top: 1.5rem; margin-top: .5rem; }
        .dark .whatsapp-settings-status { border-top-color: var(--gray-800); }
        .whatsapp-settings-reminder > .fi-grid { align-items: stretch; gap: 1.25rem; }
        .whatsapp-settings-reminder > .fi-grid > .fi-grid-col > .fi-sc-component,
        .whatsapp-settings-reminder .whatsapp-settings-panel,
        .whatsapp-settings-reminder .whatsapp-settings-panel > .fi-section { height: 100%; }
        .whatsapp-settings-reminder .whatsapp-settings-panel > .fi-section { display: flex; flex-direction: column; }
        .whatsapp-settings-reminder .fi-section-content-ctn { display: flex; flex: 1; flex-direction: column; }
        .whatsapp-settings-reminder .fi-section-footer { margin-top: auto; }
        .whatsapp-settings-panel .fi-section-header { align-items: center; min-height: 3.5rem; padding-inline: 1.25rem; gap: .75rem; }
        .whatsapp-settings-panel .fi-section-header-text-ctn { min-width: 0; }
        .whatsapp-settings-panel .fi-section-header-after-ctn { flex-shrink: 0; }
        .whatsapp-settings-panel .fi-section-content { padding: 1.25rem; }
        .whatsapp-settings-schedule > .fi-grid { row-gap: 1.25rem; column-gap: 1rem; }
        .whatsapp-settings-schedule .fi-in-entry { min-width: 0; }
        .whatsapp-settings-schedule .fi-in-entry-content { font-variant-numeric: tabular-nums; }
        .whatsapp-settings-tabs { gap: 1.5rem; align-items: flex-start; }
        .whatsapp-settings-tabs > .fi-tabs { flex: 0 0 14rem; align-self: flex-start; }
        .whatsapp-settings-tabs > .fi-tabs > .fi-tabs-item { min-height: 44px; text-align: left; }
        .whatsapp-settings-tabs > .fi-tabs > .fi-tabs-item[aria-selected="true"] { font-weight: 600; }
        .whatsapp-settings-tabs > .fi-tabs > .fi-tabs-item:focus-visible { outline: 2px solid var(--color-primary-500); outline-offset: -2px; }
        .whatsapp-settings-tabs > .fi-sc-tabs-tab { min-width: 0; }
        .whatsapp-settings-tabs > .fi-sc-tabs-tab > .fi-sc { gap: 1.25rem; }
        .whatsapp-message-preview { display: grid; gap: .75rem; }
        .whatsapp-message-preview__note { margin: 0; font-size: .8125rem; line-height: 1.5; color: #52525b; }
        .dark .whatsapp-message-preview__note { color: #a1a1aa; }
        .whatsapp-message-preview__body { margin: 0; max-width: 65ch; white-space: pre-line; overflow-wrap: anywhere; font-size: .9375rem; line-height: 1.7; }
        @media (min-width: 768px) {
            .whatsapp-settings-tabs > .fi-tabs { position: sticky; top: 5rem; }
        }
        @media (max-width: 767px) {
            .whatsapp-settings-status { padding-top: 1.25rem; margin-top: 0; }
            .whatsapp-settings-panel .fi-section-header { padding-inline: 1rem; }
            .whatsapp-settings-panel .fi-section-header-heading { font-size: .9375rem; line-height: 1.5; }
            .whatsapp-settings-panel .fi-section-content { padding: 1rem; }
            .whatsapp-settings-panel .fi-section-header .fi-btn { min-height: 44px; }
            .whatsapp-settings-panel .fi-section-footer .fi-link { min-height: 44px; display: inline-flex; align-items: center; }
            .whatsapp-settings-tabs.fi-vertical { flex-direction: column; gap: 1rem; }
            .whatsapp-settings-tabs > .fi-tabs.fi-vertical { flex-direction: row; flex-basis: auto; max-width: 100%; width: 100%; overflow-x: auto; scrollbar-width: thin; }
            .whatsapp-settings-tabs.fi-vertical > .fi-sc-tabs-tab.fi-active { margin-inline-start: 0; width: 100%; }
            .whatsapp-settings-tabs > .fi-tabs > .fi-tabs-item { min-height: 44px; flex-shrink: 0; }
        }
    </style>
@endonce

<div class="whatsapp-message-preview">
    <p class="whatsapp-message-preview__note">Contoh pesan</p>
    <p class="whatsapp-message-preview__body">{{ $getState() }}</p>
</div>
