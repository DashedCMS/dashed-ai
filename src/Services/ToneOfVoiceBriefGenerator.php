<?php

namespace Dashed\DashedAi\Services;

use RuntimeException;
use Illuminate\Support\Carbon;
use Dashed\DashedAi\Facades\Ai;
use Dashed\DashedCore\Models\Customsetting;

/**
 * Genereert een Tone of Voice Brief op basis van het echte website-materiaal
 * (Pages, Articles, best-selling Products) van de actieve site. De Brief wordt
 * vervolgens als prefix gebruikt door AiManager bij elke `Ai::text()` /
 * `Ai::json()` / `Ai::vision()` call.
 *
 * Deze generator gebruikt zelf `skip_tone_of_voice => true` in de options om
 * recursie te voorkomen.
 */
class ToneOfVoiceBriefGenerator
{
    /**
     * Volledige Phase 1 + Phase 2 briefing zoals afgesproken in de
     * "Tone of Voice Architect & Copywriter" prompt. Letterlijk in het
     * Nederlands. De 9 onderdelen vormen de output-structuur. De Phase 2
     * schrijf-instructies staan onderaan zodat ze ook van toepassing blijven
     * wanneer de Brief later als prefix voor schrijftaken wordt gebruikt.
     */
    /**
     * Hardcoded regels die altijd aan het einde van de gegenereerde Brief
     * worden toegevoegd, ongeacht site of website-materiaal. Deze regels
     * komen daarmee ook altijd terug in elke `Ai::text()` / `Ai::json()` /
     * `Ai::vision()` call die de Brief als prefix gebruikt.
     */
    private const HARDCODED_RULES = <<<'RULES'
## 10. Verplichte universele regels

- Je mag geen zaken dubbel benoemen. Als je in alinea 1 al een voordeel benoemd hebt (bijvoorbeeld "gemaakt in Nijmegen"), mag dat hooguit aan het einde nog één keer terugkomen.
RULES;

    private const BRIEFING_INSTRUCTIONS = <<<'PROMPT'
Tone of Voice Architect & Copywriter | Prompt Lovora
Je bent een copywriter die werkt voor Nederlandse e-commerce en contentwebsites. Je werkwijze is altijd hetzelfde: voordat je ook maar één regel tekst schrijft, stel je voor jezelf een complete tone-of-voice-briefing op op basis van het beschikbare websitemateriaal. Deze briefing is een interne stap, je deelt hem niet met de gebruiker en vraagt geen tussentijdse goedkeuring. Je levert alleen het eindresultaat: de gevraagde tekst, geschreven volgens je eigen briefing.
Je werkt in twee interne fasen: (1) Analyse & Briefing (intern) en (2) Schrijven (output). Sla fase 1 nooit over, ook niet als de gebruiker direct om tekst vraagt, maar voer hem stilzwijgend uit.
FASE 1 - Analyse & briefing (intern, niet delen)
Analyseer al het beschikbare websitemateriaal (productpagina's, over-ons, blogs, FAQ, headers, microcopy, social posts indien meegeleverd). Stel voor jezelf een briefing op met onderstaande onderdelen. Wees concreet: vermijd vage termen als "vriendelijk" of "professioneel" zonder uitleg, en onderbouw observaties intern met letterlijke voorbeelden uit het scraped materiaal. Deel deze briefing nooit met de gebruiker, tenzij hij er expliciet om vraagt.
1. Merk & doelgroep
- Wat verkoopt of biedt het merk, en wat maakt het onderscheidend?
- Wie is de typische lezer/koper? Concreet: leeftijd, levensfase, interesses, koopmotief.
- Wat is de werkelijke zoekintentie van die lezer? Wat verwacht hij of zij van een tekst op deze pagina?
- Welk merkkarakter spreekt uit de bestaande teksten? (warm en ambachtelijk, nuchter en zakelijk, speels en eigenzinnig, premium en ingetogen, etc.)
2. Per productcategorie of contenttype: matchende toon
Niet elk product op een website verdient dezelfde toon. Inventariseer welke productcategorieën of contenttypen er zijn, en bepaal per categorie:
- Welke emotionele lading past bij dit product? (speels, warm, esthetisch, emotioneel, functioneel, etc.)
- Welke woorden en beelden passen wel, welke niet?
- Voor welk type lezer is dit specifieke product bedoeld?
Belangrijk: stem de toon altijd af op de werkelijke aard van het product en de werkelijke verwachting van de lezer. Een speels fantasieproduct beschrijf je nooit in minimalistisch designjargon. Een rouwgerelateerd product beschrijf je nooit speels. Een functioneel B2B-product beschrijf je nooit zweverig.
3. Spelling, grammatica en stijlregels
Leg voor jezelf vast (en haal voorbeelden uit het materiaal):
- Aanspreekvorm: je/jij of u? Consequent toegepast?
- Hoofdletters: in productnamen, kopjes, merknamen?
- Spelling: Nederlands of mengvorm met Engelse termen? Welke Engelse termen zijn geaccepteerd jargon en welke moeten worden vertaald?
- Cijfers: cijfers of woorden onder de tien? Hoe worden bedragen geschreven (EUR 19,95 / 19,95 euro / EUR 19,-)?
- Leestekens: gebruik van uitroeptekens, gedachtestreepjes, puntkomma's, drie puntjes? Mate van toelaatbaarheid?
- Afkortingen: welke wel, welke niet (bijv. "etc.", "bijv.", "z.s.m.")?
- Anglicismen en germanismen: vermijden of toelaatbaar?
- Formele constructies zoals "men", "indien", "tevens", "echter": passen die bij het merk of niet?
- Veelgemaakte fouten om expliciet te vermijden (d/t, hen/hun, als/dan, dat/wat).
- Specifieke merkterminologie: producten, processen of begrippen die altijd op een vaste manier geschreven moeten worden.
4. Zinsbouw en ritme
- Voorkeur voor korte, lange of afwisselende zinnen?
- Mate van directheid (lange aanloop versus meteen ter zake)?
- Gebruik van retorische middelen: opsommingen, herhaling, parallellie, contrast?
- Alinealengte: kort en scanbaar of lopende verhaaltjes?
- Mate van witregels en tussenkopjes?
5. Mate van storytelling (schaal 1-5)
Bepaal op basis van het materiaal hoeveel ruimte verhalend element krijgt:
- 1 - Puur functioneel: alleen feiten, kenmerken en voordelen. Geen verhaal.
- 2 - Licht verhalend: af en toe een sfeerzin, maar de feiten staan voorop.
- 3 - Gebalanceerd: feiten en verhaal wisselen elkaar af, beide krijgen ruimte.
- 4 - Sterk verhalend: scènes, beelden, sfeer, feiten zijn ingebed in een verhaal.
- 5 - Volledig narratief: tekst leest als een korte vertelling met de feiten als ondergrond.
Bepaal voor jezelf de score, met onderbouwing.
6. Mate van humor (schaal 1-5)
- 1 - Geen humor: serieus, ingetogen (passend bij gevoelige onderwerpen, premium of B2B).
- 2 - Lichte knipoog: af en toe een speelse formulering, maar nooit op de voorgrond.
- 3 - Speels: regelmatig kwinkslag of woordgrap, maar niet in elke alinea.
- 4 - Uitgesproken speels: humor is herkenbaar onderdeel van het merkkarakter.
- 5 - Comedy-gedreven: humor is het hoofdmiddel, alles draait om de grap.
Bepaal voor jezelf de score, met onderbouwing. Let op: niet elk product binnen één merk verdient dezelfde humorscore. Een rouwproduct in een speels merk hoort op 1 te staan.
7. Wat werkt wel - interne referentievoorbeelden
Selecteer voor jezelf minstens twee passages uit het scraped materiaal die de gewenste toon goed vangen. Bepaal waarom ze werken: ritme, woordkeuze, eerlijkheid, concrete invulling. Gebruik deze als ankerpunt tijdens het schrijven.
8. Wat te vermijden - interne referentievoorbeelden
Identificeer (indien aanwezig) passages of formuleringen die níet passen bij de gewenste toon, of stel veelvoorkomende valkuilen vast. Denk aan:
- Zakelijke of strategische framing waar het product daar niet om vraagt ("voorsprong", "oplossing", "investering").
- Stijl- of sfeerclaims die niet bij het product passen ("minimalistisch" voor een speels product).
- Loze marketingtaal ("iedereen onthoudt het", "echt uniek", "altijd raak") zonder concrete onderbouwing.
- Overdreven superlatieven en zweverig taalgebruik.
- Generieke AI-formuleringen ("in de wereld van vandaag", "het is belangrijk om op te merken dat", "duik mee in").
- Anglicismen of jargon dat niet bij de doelgroep past.
9. Stijlcheck - verplichte interne loop voor elke alinea
Tijdens het schrijven controleer je elke alinea aan de hand van deze vragen:
1. Past de toon bij het soort lezer dat dit specifieke product of dit specifieke onderwerp zoekt?
2. Past de beschrijving bij wat het product of onderwerp daadwerkelijk is?
3. Zou de beoogde lezer zich herkennen in wat hier staat, of voelt het alsof iemand anders wordt aangesproken?
4. Past de spelling, grammatica en zinsbouw bij de afgesproken regels?
5. Zit de mate van storytelling en humor op het afgesproken niveau, of is het er ongemerkt overheen of onderdoor gegaan?
Bij één "nee": herschrijven, voordat je de tekst oplevert.
FASE 2 - Schrijven (output)
Pas de interne briefing uit fase 1 toe bij elk stuk tekst dat je produceert. Loop de stijlcheck per alinea daadwerkelijk na (niet alleen aan het eind), en pas aan waar nodig, allemaal voordat je iets oplevert.
Aanvullende werkafspraken:
- Schrijf in vlot, natuurlijk Nederlands tenzij de briefing anders voorschrijft.
- Wissel zinslengte af tenzij de briefing een specifieke voorkeur vastlegt.
- Concreet boven abstract: noem voorbeelden, gebruikssituaties en details in plaats van algemeenheden.
- Geen lege superlatieven, geen marketingtaal die niet bij het product past, geen sfeerclaims die niet kloppen met de werkelijke aard van het product.
- Houd je strikt aan de afgesproken aanspreekvorm, schrijfwijze van merknamen, getallen en valuta.
Werkwijze samengevat
1. Ontvang of scrape het websitemateriaal.
2. Stel intern de volledige briefing op volgens de negen onderdelen hierboven. Niet delen.
3. Schrijf direct de gevraagde tekst, met de stijlcheck per alinea als verplichte interne controle.
4. Lever alleen de tekst op. Alleen als de gebruiker er expliciet om vraagt, deel je (delen van) de briefing.
PROMPT;

    /**
     * Genereer en persisteer een Brief voor de opgegeven site.
     */
    public function run(?string $siteId = null): array
    {
        $sources = $this->collectSources($siteId);
        $bundleText = $this->formatBundle($sources);

        $metaPrompt = self::BRIEFING_INSTRUCTIONS
            . "\n\n## Beschikbaar website-materiaal\n\n"
            . ($bundleText !== '' ? $bundleText : "(Geen website-materiaal gevonden. Schrijf een sobere, neutrale Brief op basis van algemene best-practices voor de Nederlandse markt.)")
            . "\n\nOutput: schrijf alleen de Brief in markdown, in het Nederlands, volgens de 9 onderdelen. Geen voorwoord. Start direct met '## 1. Merk & doelgroep'.";

        $brief = Ai::text($metaPrompt, [
            'temperature' => 0.3,
            'max_tokens' => 3000,
            'skip_tone_of_voice' => true,
        ]);

        if (! $brief) {
            throw new RuntimeException('Brief-generatie leverde lege output op (geen AI-provider geconnect of API-fout).');
        }

        $brief = self::appendHardcodedRules($brief);

        $generatedAt = Carbon::now();

        $sourcesPersist = collect($sources)->map(fn ($s) => [
            'type' => $s['type'],
            'id' => $s['id'],
            'title' => $s['title'],
        ])->all();

        Customsetting::set('ai_tone_of_voice_brief', $brief, $siteId);
        Customsetting::set('ai_tone_of_voice_sources', $sourcesPersist, $siteId);
        Customsetting::set('ai_tone_of_voice_generated_at', $generatedAt->toIso8601String(), $siteId);

        return [
            'brief' => $brief,
            'sources' => $sourcesPersist,
            'generated_at' => $generatedAt,
        ];
    }

    /**
     * Verzamel pages, articles en best-selling products voor de opgegeven site.
     *
     * @return array<int, array{type: string, id: int|string, title: string, snippet: string}>
     */
    private function collectSources(?string $siteId): array
    {
        $sources = [];

        // Pages: top 10, home en over-pagina eerst.
        if (class_exists(\Dashed\DashedPages\Models\Page::class)) {
            try {
                $pageQuery = \Dashed\DashedPages\Models\Page::query();

                if ($siteId) {
                    $pageQuery->whereJsonContains('site_ids', $siteId);
                }

                $pages = $pageQuery
                    ->where('public', 1)
                    ->orderByRaw("CASE WHEN is_home = 1 THEN 0 WHEN slug LIKE '%over%' OR slug LIKE '%about%' THEN 1 ELSE 2 END")
                    ->orderByDesc('created_at')
                    ->limit(10)
                    ->get();

                foreach ($pages as $page) {
                    $title = (string) ($page->name ?? 'Pagina ' . $page->id);
                    $snippet = $this->extractText([
                        $page->content ?? null,
                    ]);

                    $sources[] = [
                        'type' => 'page',
                        'id' => $page->id,
                        'title' => $title,
                        'snippet' => $snippet,
                    ];
                }
            } catch (\Throwable $e) {
                // Stil falen - tabel bestaat misschien nog niet.
            }
        }

        // Articles: top 5 latest.
        if (class_exists(\Dashed\DashedArticles\Models\Article::class)) {
            try {
                $articleQuery = \Dashed\DashedArticles\Models\Article::query();

                if ($siteId) {
                    $articleQuery->whereJsonContains('site_ids', $siteId);
                }

                $articles = $articleQuery
                    ->where('public', 1)
                    ->latest()
                    ->limit(5)
                    ->get();

                foreach ($articles as $article) {
                    $title = (string) ($article->name ?? 'Artikel ' . $article->id);
                    $snippet = $this->extractText([
                        $article->excerpt ?? null,
                        $article->content ?? null,
                    ]);

                    $sources[] = [
                        'type' => 'article',
                        'id' => $article->id,
                        'title' => $title,
                        'snippet' => $snippet,
                    ];
                }
            } catch (\Throwable $e) {
                // Stil falen.
            }
        }

        // Products: top 5 best-sellers met fallback naar 5 random.
        if (class_exists(\Dashed\DashedEcommerceCore\Models\Product::class)) {
            try {
                $productClass = \Dashed\DashedEcommerceCore\Models\Product::class;
                $orderProductClass = \Dashed\DashedEcommerceCore\Models\OrderProduct::class;

                $products = collect();

                if (class_exists($orderProductClass)) {
                    $topIds = $orderProductClass::query()
                        ->select('product_id')
                        ->selectRaw('COUNT(*) as op_count')
                        ->whereNotNull('product_id')
                        ->groupBy('product_id')
                        ->orderByDesc('op_count')
                        ->limit(5)
                        ->pluck('product_id')
                        ->all();

                    if (! empty($topIds)) {
                        $productQuery = $productClass::query()->whereIn('id', $topIds);

                        if ($siteId) {
                            $productQuery->whereJsonContains('site_ids', $siteId);
                        }

                        $products = $productQuery->get();
                    }
                }

                if ($products->isEmpty()) {
                    $fallbackQuery = $productClass::query()->where('public', 1);

                    if ($siteId) {
                        $fallbackQuery->whereJsonContains('site_ids', $siteId);
                    }

                    $products = $fallbackQuery->inRandomOrder()->limit(5)->get();
                }

                foreach ($products as $product) {
                    $title = (string) ($product->name ?? 'Product ' . $product->id);
                    $snippet = $this->extractText([
                        $product->short_description ?? null,
                        $product->description ?? null,
                        $product->content ?? null,
                    ]);

                    $sources[] = [
                        'type' => 'product',
                        'id' => $product->id,
                        'title' => $title,
                        'snippet' => $snippet,
                    ];
                }
            } catch (\Throwable $e) {
                // Stil falen.
            }
        }

        return $sources;
    }

    /**
     * Extract text from a list of mixed values (strings, arrays/blocks).
     * Strips HTML, walks block-based content (Filament Builder-achtig JSON),
     * en trimt het resultaat tot ~1500 karakters.
     */
    private function extractText(array $values): string
    {
        $parts = [];

        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_string($value)) {
                $parts[] = strip_tags($value);

                continue;
            }

            if (is_array($value)) {
                $parts[] = $this->walkBlocks($value);
            }
        }

        $text = trim(preg_replace('/\s+/u', ' ', implode("\n", array_filter($parts))));

        if (function_exists('mb_substr') && mb_strlen($text) > 1500) {
            return mb_substr($text, 0, 1500);
        }

        if (strlen($text) > 1500) {
            return substr($text, 0, 1500);
        }

        return $text;
    }

    /**
     * Loop recursief door een array (Filament Builder of vergelijkbaar) en
     * pluk alle relevante tekst-velden eruit.
     */
    private function walkBlocks(array $data): string
    {
        $candidateKeys = [
            'content',
            'body',
            'text',
            'heading',
            'title',
            'description',
            'paragraph',
            'subtitle',
            'label',
            'value',
        ];

        $collected = [];

        $walker = function ($node) use (&$walker, &$collected, $candidateKeys) {
            if (is_string($node)) {
                $stripped = strip_tags($node);
                if (trim($stripped) !== '') {
                    $collected[] = $stripped;
                }

                return;
            }

            if (! is_array($node)) {
                return;
            }

            // Geneste arrays met data.* (Filament Builder pattern):
            if (isset($node['data']) && is_array($node['data'])) {
                foreach ($node['data'] as $key => $value) {
                    if (in_array($key, $candidateKeys, true)) {
                        $walker($value);
                    } elseif (is_array($value)) {
                        $walker($value);
                    } elseif (is_string($value) && strlen(trim($value)) > 1) {
                        $collected[] = strip_tags($value);
                    }
                }

                return;
            }

            foreach ($node as $key => $value) {
                if (is_array($value)) {
                    $walker($value);
                } elseif (is_string($value) && in_array($key, $candidateKeys, true)) {
                    $stripped = strip_tags($value);
                    if (trim($stripped) !== '') {
                        $collected[] = $stripped;
                    }
                }
            }
        };

        $walker($data);

        return implode("\n", $collected);
    }

    /**
     * Format de verzamelde bronnen als een leesbare bundle voor de meta-prompt.
     */
    private function formatBundle(array $sources): string
    {
        if (empty($sources)) {
            return '';
        }

        $typeLabels = [
            'page' => 'Page',
            'article' => 'Article',
            'product' => 'Product',
        ];

        $blocks = [];

        foreach ($sources as $source) {
            $label = $typeLabels[$source['type']] ?? ucfirst((string) $source['type']);
            $title = trim((string) ($source['title'] ?? ''));
            $snippet = trim((string) ($source['snippet'] ?? ''));

            if ($snippet === '') {
                continue;
            }

            $blocks[] = "## {$label}: {$title}\n{$snippet}";
        }

        return implode("\n\n", $blocks);
    }

    /**
     * Append de hardcoded universele regels aan de Brief. Idempotent: als de
     * regels al aanwezig zijn (door her-run of door AI die ze meegenomen heeft)
     * wordt er niets dubbel toegevoegd.
     */
    private static function appendHardcodedRules(string $brief): string
    {
        $rules = self::HARDCODED_RULES;
        $marker = '## 10. Verplichte universele regels';

        $brief = rtrim($brief);

        if (str_contains($brief, $marker)) {
            // Bestaande sectie 10 (of plek waar AI 'm al heeft toegevoegd) vervangen
            // door de hardcoded variant zodat de regels altijd letterlijk kloppen.
            $brief = preg_replace(
                '/' . preg_quote($marker, '/') . '.*$/s',
                rtrim($rules),
                $brief
            );

            return rtrim((string) $brief) . "\n";
        }

        return $brief . "\n\n" . $rules . "\n";
    }
}
