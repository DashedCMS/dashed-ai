# Changelog

All notable changes to `dashed-ai` will be documented in this file.

## v4.0.6 - 2026-04-27

### Added
- `AiProvider::buildSystemPrompt()` accepteert nu drie nieuwe options:
  - `disable_brand_rules` (bool) — bypass de globale Nederlandse brand-rules en merkverhaal/schrijfstijl. Voor technische Engelstalige prompts (zoals image-prompt-generatie) zodat het system-prompt niet vervuild wordt door schrijfstijl-instructies in de verkeerde taal.
  - `brand_story` (string) — override voor `Customsetting('ai_brand_story')` zodat consumers brand-context dynamisch per call kunnen meegeven.
  - `writing_style` (string) — idem voor `Customsetting('ai_writing_style')`.
