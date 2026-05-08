# Changelog

All notable changes to `dashed-ai` will be documented in this file.

## v4.1.2 - 2026-05-08

### Changed
- **Tone-of-voice Brief gemerged in AI-instellingen-pagina.** De standalone `AiToneOfVoiceSettingsPage` is verwijderd; alle Brief-functionaliteit zit nu in een sectie "Uitgebreide tone-of-voice Brief" op de bestaande `AiSettingsPage`. Eén plek voor merkverhaal + schrijfstijl + Brief.
- **Brief is nu opt-in via een toggle** (`ai_tone_of_voice_brief_enabled`, default `false`). `AiManager::resolveToneOfVoiceBrief()` returnt alleen een Brief wanneer de toggle aan staat, zodat bestaande sites met merkverhaal + schrijfstijl hun gedrag behouden bij update.
- AI-instellingen-pagina toont nu read-only de actuele Brief, een override-textarea, en een helper-text "Laatst gegenereerd op X (Y dagen geleden)". Twee header-actions: "Vernieuw tone-of-voice Brief" (dispatcht `GenerateToneOfVoiceBriefJob`) en "Reset Brief" (wist alle Brief-Customsettings).

## v4.1.0 - 2026-05-08

### Added
- AI Tone of Voice Brief: nieuwe pijplijn die per site automatisch een Tone of Voice Brief genereert op basis van het echte website-materiaal (paginas, artikelen, best-selling producten) en deze als context-prefix meegeeft aan elke `Ai::text()`, `Ai::json()` en `Ai::vision()` aanroep.
  - `Dashed\DashedAi\Services\ToneOfVoiceBriefGenerator` - bevat de hardcoded Phase 1 + Phase 2 briefing (9 onderdelen) en bouwt de meta-prompt + persisteert de Brief, bronnen en timestamp in `Customsetting`.
  - `Dashed\DashedAi\Jobs\GenerateToneOfVoiceBriefJob` - queueable wrapper rond de generator, met 3 tries en exponential backoff (60/300/900s).
  - `Dashed\DashedAi\Commands\RefreshToneOfVoiceBriefCommand` (`dashed:refresh-tone-of-voice-brief`) - daily scheduler-command die per site dispatcht zodra de Brief ouder is dan `ai_tone_of_voice_max_age_days`. Met `--force` worden de leeftijds- en override-check overgeslagen. Ingeschreven op `daily()->withoutOverlapping()`.
  - `Dashed\DashedAi\Filament\Pages\Settings\AiToneOfVoiceSettingsPage` - admin-pagina onder Instellingen met read-only Brief, override-veld, max-age slider en de header-actions "Vernieuw Brief nu" en "Reset Brief".
  - `AiManager::prependToneOfVoice()` - middleware die elke text/json/vision-prompt voorvoegt met de Brief. Override gaat voor de gegenereerde Brief, geen Brief geconfigureerd betekent geen prefix (backwards-compat). Caller kan dit uitschakelen met `['skip_tone_of_voice' => true]` (gebruikt door de generator zelf om recursie te voorkomen).

## v4.0.6 - 2026-04-27

### Added
- `AiProvider::buildSystemPrompt()` accepteert nu drie nieuwe options:
  - `disable_brand_rules` (bool) - bypass de globale Nederlandse brand-rules en merkverhaal/schrijfstijl. Voor technische Engelstalige prompts (zoals image-prompt-generatie) zodat het system-prompt niet vervuild wordt door schrijfstijl-instructies in de verkeerde taal.
  - `brand_story` (string) - override voor `Customsetting('ai_brand_story')` zodat consumers brand-context dynamisch per call kunnen meegeven.
  - `writing_style` (string) - idem voor `Customsetting('ai_writing_style')`.
