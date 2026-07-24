# Unified Free-Text Photo Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the three stacked search controls on the public event gallery (colour checkboxes, garment checkboxes, bib field) with one free-text box that parses bib numbers, clothing colours, garments, and scenes and returns matching photos.

**Architecture:** A new server-side `PhotoSearchQueryParser` turns the raw `?q=` string into an ordered list of typed tokens (bib / colour / garment / scene) plus ignored words, using a longest-phrase-first synonym dictionary and a conservative fuzzy colour fallback. The controller feeds the parsed result into an extended `PhotoAttributeFilter`, and `PhotoRepository::searchReady` is rewritten to the semantics `results = P_bib ∪ (P_colour ∩ P_garment ∩ P_scene)` using EXISTS subqueries. The template renders recognized tokens as chips whose `×` is a plain link to the same search minus that token.

**Tech Stack:** PHP 8.5, Symfony 8, Doctrine ORM 3 (DQL), Twig, PHPUnit 13.

## Global Constraints

- PHP attributes only — no annotations.
- PHPStan level 10 across `src`, `tests`, `public`; PSR-12 (`phpcs`); no magic numbers in `src` (`phpmnd` — extract literals to named constants); `rector`; `phpcpd` (50-line / 100-token duplication); `doctrine:schema:validate`; full `phpunit` suite. All gate every commit via GrumPHP.
- Vocabulary source of truth: `App\Service\Photo\AttributeVocabulary` — `COLORS` = `black white grey red orange yellow green blue purple pink brown beige`; `GARMENTS` = `t-shirt "long-sleeve shirt" jacket hoodie/sweater dress shorts trousers skirt hat/cap`; `SCENES` = `start finish-line on-course/running water-station crowd/spectators medal/podium`. Do not invent vocabulary values — only these canonical strings may appear in filters.
- Run PHP / Composer / `bin/console` / `vendor/bin/*` on the host.
- Do NOT run `git commit` — the user commits. Each task's "Commit" step means: `git add` the files and STOP, reporting the staged diff and the proposed one-line message (must contain issue number `117`).
- New value objects and parser live under `App\Service\Photo\Search`; their tests under `tests/Unit/Service/Photo/Search`.

---

### Task 1: Add `scenes` to `PhotoAttributeFilter`

**Files:**
- Modify: `src/Repository/Filter/PhotoAttributeFilter.php`
- Test: `tests/Unit/Repository/Filter/PhotoAttributeFilterTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `PhotoAttributeFilter(list<string> $colours = [], list<string> $garments = [], ?string $bib = null, list<string> $scenes = [])` with public readonly props and `isEmpty(): bool` that is true only when colours, garments, scenes are all `[]` AND bib is `null`.

- [ ] **Step 1: Read the current test file** `tests/Unit/Repository/Filter/PhotoAttributeFilterTest.php` to match its style, then add failing cases.

Append these test methods (adapt to the existing class/namespace already in the file):

```php
public function testScenesDefaultEmptyAndIsEmptyTrue(): void
{
    $filter = new PhotoAttributeFilter();

    self::assertSame([], $filter->scenes);
    self::assertTrue($filter->isEmpty());
}

public function testScenesOnlyMakesFilterNonEmpty(): void
{
    $filter = new PhotoAttributeFilter(scenes: ['finish-line']);

    self::assertSame(['finish-line'], $filter->scenes);
    self::assertFalse($filter->isEmpty());
}
```

- [ ] **Step 2: Run the tests, verify they fail**

Run: `SKIP_SCHEMA_REBUILD=1 vendor/bin/phpunit tests/Unit/Repository/Filter/PhotoAttributeFilterTest.php`
Expected: FAIL — unknown named argument `$scenes` / property `scenes` not defined.

- [ ] **Step 3: Add the `scenes` property**

Replace the constructor and `isEmpty()` in `src/Repository/Filter/PhotoAttributeFilter.php`:

```php
    /**
     * @param list<string> $colours
     * @param list<string> $garments
     * @param list<string> $scenes
     */
    public function __construct(
        public array $colours = [],
        public array $garments = [],
        public ?string $bib = null,
        public array $scenes = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->colours === []
            && $this->garments === []
            && $this->scenes === []
            && $this->bib === null;
    }
```

- [ ] **Step 4: Run the tests, verify they pass**

Run: `SKIP_SCHEMA_REBUILD=1 vendor/bin/phpunit tests/Unit/Repository/Filter/PhotoAttributeFilterTest.php`
Expected: PASS.

- [ ] **Step 5: Static analysis on the changed files**

Run: `vendor/bin/phpstan analyse src/Repository/Filter/PhotoAttributeFilter.php tests/Unit/Repository/Filter/PhotoAttributeFilterTest.php`
Expected: `[OK] No errors`.

- [ ] **Step 6: Stage (do not commit)**

```bash
git add src/Repository/Filter/PhotoAttributeFilter.php tests/Unit/Repository/Filter/PhotoAttributeFilterTest.php
```
Proposed message: `117 - photo search filter: add scenes dimension`

---

### Task 2: Rewrite `searchReady` to `P_bib ∪ (P_colour ∩ P_garment ∩ P_scene)`

**Files:**
- Modify: `src/Repository/PhotoRepository.php:63-118` (the `searchReady` method) and its docblock at `:55-62`.
- Test: `tests/Integration/Repository/PhotoRepositorySearchTest.php`

**Interfaces:**
- Consumes: `PhotoAttributeFilter` with `scenes` (Task 1).
- Produces: `searchReady(Event $event, PhotoAttributeFilter $filter, int $limit): list<Photo>` where a photo matches if it matches the bib term OR the attribute group. Attribute group = the AND of every non-empty dimension (colour/garment/scene), each dimension OR-ing its own values via `IN`. Bib term = has the bib tag AND that bib is not suppressed. Empty dimensions are omitted from the AND; an all-empty filter returns `[]`. Ordered by `takenAt ASC, id ASC`, capped at `$limit`, no duplicate rows.

- [ ] **Step 1: Add failing integration tests**

Append to `tests/Integration/Repository/PhotoRepositorySearchTest.php` (the file already imports `Photo`, `PhotoAttributeType`, `PhotoAttributeFilter`, `PhotoFixtures`, `BibSuppression`). Add a small local helper mapper at the top of each test as the existing tests do:

```php
public function testBibIsUnionedWithAttributes(): void
{
    $event = PhotoFixtures::event($this->em, bibIndexing: true);

    // Matches by bib only (wrong colour).
    $bibOnly = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
    PhotoFixtures::tagBib($this->em, $bibOnly, '1423');
    PhotoFixtures::tagColour($this->em, $bibOnly, 'black');

    // Matches by attributes only (wrong bib).
    $attrOnly = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:01:00');
    PhotoFixtures::tagBib($this->em, $attrOnly, '2000');
    PhotoFixtures::tagColour($this->em, $attrOnly, 'red');

    // Matches neither.
    $neither = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:02:00');
    PhotoFixtures::tagColour($this->em, $neither, 'blue');
    $this->em->flush();

    $result = $this->repo->searchReady(
        $event,
        new PhotoAttributeFilter(colours: ['red'], bib: '1423'),
        200,
    );

    $ids = array_map(static fn (Photo $p): ?int => $p->getId(), $result);
    self::assertContains($bibOnly->getId(), $ids);
    self::assertContains($attrOnly->getId(), $ids);
    self::assertNotContains($neither->getId(), $ids);
}

public function testSceneFilterNarrowsToMatchingPhotos(): void
{
    $event  = PhotoFixtures::event($this->em);
    $finish = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
    $start  = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:01:00');
    PhotoFixtures::tag($this->em, $finish, PhotoAttributeType::Scene, 'finish-line');
    PhotoFixtures::tag($this->em, $start, PhotoAttributeType::Scene, 'start');
    $this->em->flush();

    $result = $this->repo->searchReady($event, new PhotoAttributeFilter(scenes: ['finish-line']), 200);

    self::assertSame([$finish->getId()], array_map(static fn (Photo $p): ?int => $p->getId(), $result));
}

public function testColourAndSceneAreAnded(): void
{
    $event = PhotoFixtures::event($this->em);
    $match = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
    $half  = PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:01:00');
    PhotoFixtures::tagColour($this->em, $match, 'red');
    PhotoFixtures::tag($this->em, $match, PhotoAttributeType::Scene, 'finish-line');
    PhotoFixtures::tagColour($this->em, $half, 'red');
    $this->em->flush();

    $result = $this->repo->searchReady(
        $event,
        new PhotoAttributeFilter(colours: ['red'], scenes: ['finish-line']),
        200,
    );

    self::assertSame([$match->getId()], array_map(static fn (Photo $p): ?int => $p->getId(), $result));
}

public function testEmptyFilterReturnsNothing(): void
{
    $event = PhotoFixtures::event($this->em);
    PhotoFixtures::readyPhoto($this->em, $event, '2026-07-15 10:00:00');
    $this->em->flush();

    self::assertSame([], $this->repo->searchReady($event, new PhotoAttributeFilter(), 200));
}
```

Note: the existing `testColourAndGarmentAreAnded`, `testMultipleColoursMatchingSamePhotoDoesNotDuplicate`, `testBibExactMatch`, and the suppression tests must still pass unchanged — the rewrite must preserve within-group OR, cross-group AND, exact bib match, and bib-suppression exclusion.

- [ ] **Step 2: Run the search tests, verify the new ones fail**

Run: `vendor/bin/phpunit tests/Integration/Repository/PhotoRepositorySearchTest.php`
Expected: the four new tests FAIL (current code AND-s bib with attributes and has no scene support); existing tests still pass.

- [ ] **Step 3: Rewrite `searchReady`**

Replace the whole `searchReady` method (and update its docblock) in `src/Repository/PhotoRepository.php`. Ensure `use Doctrine\ORM\Query\Expr\Join;` stays only if still referenced elsewhere — after this change `searchReady` no longer uses `Join`; if no other method uses it, remove the import (run phpcs in Step 6 to confirm). Keep `PhotoAttribute`, `PhotoAttributeType`, `PhotoStatus` imports.

```php
    /**
     * Filtered gallery search over allowlisted tags. Semantics:
     *   results = P_bib ∪ (P_colour ∩ P_garment ∩ P_scene)
     * Each present attribute dimension is an EXISTS-IN subquery (values within a
     * dimension OR-ed, dimensions AND-ed). The bib term is EXISTS on the bib tag
     * minus any BibSuppression, OR-ed against the attribute group so a matched
     * bib surfaces a photo even when its clothing doesn't match, and vice versa.
     * EXISTS (not joins) keeps rows unique without `distinct`. Spans the whole
     * event timeline — this is a "find me" query, not a browse.
     *
     * @return list<Photo>
     */
    public function searchReady(Event $event, PhotoAttributeFilter $filter, int $limit): array
    {
        $qb = $this->createQueryBuilder('p')
            ->andWhere('p.event = :event')
            ->andWhere('p.status = :status')
            ->setParameter('event', $event)
            ->setParameter('status', PhotoStatus::Ready)
            ->orderBy('p.takenAt', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->setMaxResults($limit);

        $orX = $qb->expr()->orX();

        $andParts = [];
        if ($filter->colours !== []) {
            $andParts[] = $this->attributeExists('pac', PhotoAttributeType::ClothingColor, 'colourType', 'colours');
            $qb->setParameter('colourType', PhotoAttributeType::ClothingColor)
                ->setParameter('colours', $filter->colours);
        }
        if ($filter->garments !== []) {
            $andParts[] = $this->attributeExists('pag', PhotoAttributeType::ClothingType, 'garmentType', 'garments');
            $qb->setParameter('garmentType', PhotoAttributeType::ClothingType)
                ->setParameter('garments', $filter->garments);
        }
        if ($filter->scenes !== []) {
            $andParts[] = $this->attributeExists('pas', PhotoAttributeType::Scene, 'sceneType', 'scenes');
            $qb->setParameter('sceneType', PhotoAttributeType::Scene)
                ->setParameter('scenes', $filter->scenes);
        }
        if ($andParts !== []) {
            $orX->add(implode(' AND ', $andParts));
        }

        if ($filter->bib !== null) {
            $orX->add(
                'EXISTS ('
                . 'SELECT 1 FROM App\Entity\PhotoAttribute pab '
                . 'WHERE pab.photo = p AND pab.type = :bibType AND pab.value = :bib'
                . ') AND NOT EXISTS ('
                . 'SELECT 1 FROM App\Entity\BibSuppression bs '
                . 'WHERE bs.event = :event AND bs.bibNumber = :bib'
                . ')'
            );
            $qb->setParameter('bibType', PhotoAttributeType::Bib)
                ->setParameter('bib', $filter->bib);
        }

        if (count($orX->getParts()) === 0) {
            return [];
        }

        $qb->andWhere($orX);

        /** @var list<Photo> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    private function attributeExists(
        string $alias,
        PhotoAttributeType $type,
        string $typeParam,
        string $valuesParam,
    ): string {
        // $type is bound by the caller via :$typeParam; passed here only for signature symmetry.
        unset($type);

        return sprintf(
            'EXISTS (SELECT 1 FROM App\Entity\PhotoAttribute %1$s '
            . 'WHERE %1$s.photo = p AND %1$s.type = :%2$s AND %1$s.value IN (:%3$s))',
            $alias,
            $typeParam,
            $valuesParam,
        );
    }
```

Note on the `unset($type)`: if PHPStan/phpcs flags the unused parameter, drop `$type` from the helper signature and its call sites instead — the type value is bound by the caller, the helper only needs alias/typeParam/valuesParam. Prefer removing the parameter cleanly over `unset`.

- [ ] **Step 4: Run the full search suite, verify all pass**

Run: `vendor/bin/phpunit tests/Integration/Repository/PhotoRepositorySearchTest.php`
Expected: PASS (new + existing).

- [ ] **Step 5: Static analysis**

Run: `vendor/bin/phpstan analyse src/Repository/PhotoRepository.php tests/Integration/Repository/PhotoRepositorySearchTest.php`
Expected: `[OK] No errors`.

- [ ] **Step 6: PSR-12 check (catches leftover unused `Join` import)**

Run: `vendor/bin/phpcs src/Repository/PhotoRepository.php`
Expected: no errors. If it reports an unused `use ...Join;`, remove that import and re-run.

- [ ] **Step 7: Stage (do not commit)**

```bash
git add src/Repository/PhotoRepository.php tests/Integration/Repository/PhotoRepositorySearchTest.php
```
Proposed message: `117 - searchReady: bib OR (colour AND garment AND scene) via EXISTS + scene dimension`

---

### Task 3: Value objects — `SearchToken` and `ParsedPhotoQuery`

**Files:**
- Create: `src/Service/Photo/Search/SearchToken.php`
- Create: `src/Service/Photo/Search/ParsedPhotoQuery.php`
- Test: `tests/Unit/Service/Photo/Search/ParsedPhotoQueryTest.php`

**Interfaces:**
- Consumes: `PhotoAttributeType` (`Bib | ClothingColor | ClothingType | Scene`), `PhotoAttributeFilter` (Task 1).
- Produces:
  - `SearchToken` (readonly): `__construct(PhotoAttributeType $type, string $sourceText, list<string> $canonicals, string $label)` with public readonly props.
  - `ParsedPhotoQuery` (readonly): `__construct(list<SearchToken> $tokens, list<string> $ignored)`; methods `isEmpty(): bool` (true iff `tokens === []`), `toFilter(): PhotoAttributeFilter`, `without(int $index): self`, `serialize(): string`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo\Search;

use App\Entity\PhotoAttributeType;
use App\Service\Photo\Search\ParsedPhotoQuery;
use App\Service\Photo\Search\SearchToken;
use PHPUnit\Framework\TestCase;

final class ParsedPhotoQueryTest extends TestCase
{
    public function testEmptyWhenNoTokens(): void
    {
        self::assertTrue((new ParsedPhotoQuery([], []))->isEmpty());
        self::assertFalse((new ParsedPhotoQuery([self::bib('1423')], []))->isEmpty());
    }

    public function testToFilterCollectsCanonicalsByType(): void
    {
        $query = new ParsedPhotoQuery([
            self::bib('1423'),
            new SearchToken(PhotoAttributeType::ClothingColor, 'blue', ['blue'], 'blue'),
            new SearchToken(PhotoAttributeType::ClothingColor, 'red', ['red'], 'red'),
            new SearchToken(PhotoAttributeType::ClothingType, 'shirt', ['t-shirt', 'long-sleeve shirt'], 'shirt'),
            new SearchToken(PhotoAttributeType::Scene, 'finish', ['finish-line'], 'finish-line'),
        ], ['xyzzy']);

        $filter = $query->toFilter();

        self::assertSame('1423', $filter->bib);
        self::assertSame(['blue', 'red'], $filter->colours);
        self::assertSame(['t-shirt', 'long-sleeve shirt'], $filter->garments);
        self::assertSame(['finish-line'], $filter->scenes);
    }

    public function testToFilterDeduplicatesCanonicals(): void
    {
        $query = new ParsedPhotoQuery([
            new SearchToken(PhotoAttributeType::ClothingType, 'shirt', ['t-shirt', 'long-sleeve shirt'], 'shirt'),
            new SearchToken(PhotoAttributeType::ClothingType, 'tee', ['t-shirt'], 't-shirt'),
        ], []);

        self::assertSame(['t-shirt', 'long-sleeve shirt'], $query->toFilter()->garments);
    }

    public function testWithoutRemovesTokenByIndex(): void
    {
        $query = new ParsedPhotoQuery([self::bib('1423'), self::colour('red')], []);

        $reduced = $query->without(0);

        self::assertCount(1, $reduced->tokens);
        self::assertSame('red', $reduced->tokens[0]->sourceText);
        self::assertCount(2, $query->tokens); // original untouched (readonly)
    }

    public function testSerializeJoinsSourceTextThenIgnored(): void
    {
        $query = new ParsedPhotoQuery([self::bib('1423'), self::colour('red')], ['xyzzy']);

        self::assertSame('1423 red xyzzy', $query->serialize());
    }

    public function testWithoutThenSerializeDropsTheToken(): void
    {
        $query = new ParsedPhotoQuery([self::bib('1423'), self::colour('red')], []);

        self::assertSame('red', $query->without(0)->serialize());
    }

    private static function bib(string $value): SearchToken
    {
        return new SearchToken(PhotoAttributeType::Bib, $value, [$value], 'bib ' . $value);
    }

    private static function colour(string $value): SearchToken
    {
        return new SearchToken(PhotoAttributeType::ClothingColor, $value, [$value], $value);
    }
}
```

- [ ] **Step 2: Run it, verify failure**

Run: `SKIP_SCHEMA_REBUILD=1 vendor/bin/phpunit tests/Unit/Service/Photo/Search/ParsedPhotoQueryTest.php`
Expected: FAIL — classes not found.

- [ ] **Step 3: Create `SearchToken`**

`src/Service/Photo/Search/SearchToken.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Photo\Search;

use App\Entity\PhotoAttributeType;

final readonly class SearchToken
{
    /**
     * @param list<string> $canonicals resolved vocabulary values (or [bibNumber] for a Bib token)
     */
    public function __construct(
        public PhotoAttributeType $type,
        public string $sourceText,
        public array $canonicals,
        public string $label,
    ) {
    }
}
```

- [ ] **Step 4: Create `ParsedPhotoQuery`**

`src/Service/Photo/Search/ParsedPhotoQuery.php`:

```php
<?php

declare(strict_types=1);

namespace App\Service\Photo\Search;

use App\Entity\PhotoAttributeType;
use App\Repository\Filter\PhotoAttributeFilter;

final readonly class ParsedPhotoQuery
{
    /**
     * @param list<SearchToken> $tokens
     * @param list<string>      $ignored
     */
    public function __construct(
        public array $tokens,
        public array $ignored,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->tokens === [];
    }

    public function toFilter(): PhotoAttributeFilter
    {
        $bib      = null;
        $colours  = [];
        $garments = [];
        $scenes   = [];

        foreach ($this->tokens as $token) {
            switch ($token->type) {
                case PhotoAttributeType::Bib:
                    $bib = $token->canonicals[0] ?? null;
                    break;
                case PhotoAttributeType::ClothingColor:
                    $colours = [...$colours, ...$token->canonicals];
                    break;
                case PhotoAttributeType::ClothingType:
                    $garments = [...$garments, ...$token->canonicals];
                    break;
                case PhotoAttributeType::Scene:
                    $scenes = [...$scenes, ...$token->canonicals];
                    break;
            }
        }

        return new PhotoAttributeFilter(
            colours: array_values(array_unique($colours)),
            garments: array_values(array_unique($garments)),
            bib: $bib,
            scenes: array_values(array_unique($scenes)),
        );
    }

    public function without(int $index): self
    {
        $tokens = $this->tokens;
        unset($tokens[$index]);

        return new self(array_values($tokens), $this->ignored);
    }

    public function serialize(): string
    {
        $parts = array_map(static fn (SearchToken $t): string => $t->sourceText, $this->tokens);

        return trim(implode(' ', [...$parts, ...$this->ignored]));
    }
}
```

- [ ] **Step 5: Run it, verify pass**

Run: `SKIP_SCHEMA_REBUILD=1 vendor/bin/phpunit tests/Unit/Service/Photo/Search/ParsedPhotoQueryTest.php`
Expected: PASS.

- [ ] **Step 6: Static analysis**

Run: `vendor/bin/phpstan analyse src/Service/Photo/Search tests/Unit/Service/Photo/Search/ParsedPhotoQueryTest.php`
Expected: `[OK] No errors`.

- [ ] **Step 7: Stage (do not commit)**

```bash
git add src/Service/Photo/Search/SearchToken.php src/Service/Photo/Search/ParsedPhotoQuery.php tests/Unit/Service/Photo/Search/ParsedPhotoQueryTest.php
```
Proposed message: `117 - photo search value objects: SearchToken + ParsedPhotoQuery (toFilter/without/serialize)`

---

### Task 4: `PhotoSearchQueryParser`

**Files:**
- Create: `src/Service/Photo/Search/PhotoSearchQueryParser.php`
- Test: `tests/Unit/Service/Photo/Search/PhotoSearchQueryParserTest.php`

**Interfaces:**
- Consumes: `SearchToken`, `ParsedPhotoQuery` (Task 3), `PhotoAttributeType`, `AttributeVocabulary`.
- Produces: `PhotoSearchQueryParser::parse(string $q, bool $bibEnabled, bool $attributesEnabled): ParsedPhotoQuery`.
  - Normalizes: lowercase (`mb_strtolower`), replace `-` and `/` with spaces, collapse whitespace, split on spaces.
  - Longest-phrase-first match against a synonym dictionary → colour/garment/scene tokens (only when `$attributesEnabled`).
  - First all-digit word (`/^\d+$/`) → one Bib token when `$bibEnabled`; further digit words → ignored.
  - Leftover single word: conservative fuzzy match to a colour name (prefix ≥ 3 or Levenshtein ≤ 1, unique) when `$attributesEnabled`; else ignored.
  - Token order follows first appearance in the input.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service\Photo\Search;

use App\Entity\PhotoAttributeType;
use App\Service\Photo\Search\PhotoSearchQueryParser;
use App\Service\Photo\Search\SearchToken;
use PHPUnit\Framework\TestCase;

final class PhotoSearchQueryParserTest extends TestCase
{
    private PhotoSearchQueryParser $parser;

    protected function setUp(): void
    {
        $this->parser = new PhotoSearchQueryParser();
    }

    public function testEmptyStringParsesToEmpty(): void
    {
        self::assertTrue($this->parser->parse('', true, true)->isEmpty());
        self::assertTrue($this->parser->parse('   ', true, true)->isEmpty());
    }

    public function testBibDigitsBecomeBibTokenWhenEnabled(): void
    {
        $query = $this->parser->parse('1423', true, true);

        self::assertCount(1, $query->tokens);
        self::assertSame(PhotoAttributeType::Bib, $query->tokens[0]->type);
        self::assertSame(['1423'], $query->tokens[0]->canonicals);
        self::assertSame('bib 1423', $query->tokens[0]->label);
    }

    public function testBibIgnoredWhenDisabled(): void
    {
        $query = $this->parser->parse('1423', false, true);

        self::assertTrue($query->isEmpty());
        self::assertSame(['1423'], $query->ignored);
    }

    public function testSecondNumberIsIgnored(): void
    {
        $query = $this->parser->parse('1423 2000', true, true);

        self::assertCount(1, $query->tokens);
        self::assertSame('1423', $query->tokens[0]->canonicals[0]);
        self::assertSame(['2000'], $query->ignored);
    }

    public function testColourExactMatch(): void
    {
        $token = $this->firstOfType($this->parser->parse('red', true, true), PhotoAttributeType::ClothingColor);

        self::assertSame(['red'], $token->canonicals);
        self::assertSame('red', $token->label);
    }

    public function testGrayMapsToGrey(): void
    {
        $token = $this->firstOfType($this->parser->parse('gray', true, true), PhotoAttributeType::ClothingColor);

        self::assertSame(['grey'], $token->canonicals);
    }

    public function testHyphenatedTShirtMatches(): void
    {
        $token = $this->firstOfType($this->parser->parse('t-shirt', true, true), PhotoAttributeType::ClothingType);

        self::assertSame(['t-shirt'], $token->canonicals);
    }

    public function testGarmentSynonymSweaterMapsToHoodieSweater(): void
    {
        $token = $this->firstOfType($this->parser->parse('sweater', true, true), PhotoAttributeType::ClothingType);

        self::assertSame(['hoodie/sweater'], $token->canonicals);
    }

    public function testAmbiguousShirtMapsToBothShirts(): void
    {
        $token = $this->firstOfType($this->parser->parse('shirt', true, true), PhotoAttributeType::ClothingType);

        self::assertSame(['t-shirt', 'long-sleeve shirt'], $token->canonicals);
        self::assertSame('shirt', $token->label);
    }

    public function testLongestPhraseWinsOverShirt(): void
    {
        $token = $this->firstOfType($this->parser->parse('long sleeve shirt', true, true), PhotoAttributeType::ClothingType);

        self::assertSame(['long-sleeve shirt'], $token->canonicals);
        self::assertCount(1, $this->parser->parse('long sleeve shirt', true, true)->tokens);
    }

    public function testMultiWordSceneFinishLine(): void
    {
        $token = $this->firstOfType($this->parser->parse('finish line', true, true), PhotoAttributeType::Scene);

        self::assertSame(['finish-line'], $token->canonicals);
    }

    public function testFuzzyColourPrefix(): void
    {
        $token = $this->firstOfType($this->parser->parse('blu', true, true), PhotoAttributeType::ClothingColor);

        self::assertSame(['blue'], $token->canonicals);
    }

    public function testFuzzyColourTypo(): void
    {
        $token = $this->firstOfType($this->parser->parse('gren', true, true), PhotoAttributeType::ClothingColor);

        self::assertSame(['green'], $token->canonicals);
    }

    public function testUnknownWordIgnored(): void
    {
        $query = $this->parser->parse('banana', true, true);

        self::assertTrue($query->isEmpty());
        self::assertSame(['banana'], $query->ignored);
    }

    public function testAttributesDisabledIgnoresClothing(): void
    {
        $query = $this->parser->parse('red shirt', true, false);

        self::assertTrue($query->isEmpty());
        self::assertSame(['red', 'shirt'], $query->ignored);
    }

    public function testMixedQuery(): void
    {
        $query = $this->parser->parse('1423 red finish line banana', true, true);

        $types = array_map(static fn (SearchToken $t): string => $t->type->value, $query->tokens);
        self::assertSame(['bib', 'clothing_color', 'scene'], $types);
        self::assertSame(['banana'], $query->ignored);
    }

    public function testTokenOrderFollowsInput(): void
    {
        $query = $this->parser->parse('red 1423', true, true);

        self::assertSame(PhotoAttributeType::ClothingColor, $query->tokens[0]->type);
        self::assertSame(PhotoAttributeType::Bib, $query->tokens[1]->type);
    }

    private function firstOfType(\App\Service\Photo\Search\ParsedPhotoQuery $q, PhotoAttributeType $type): SearchToken
    {
        foreach ($q->tokens as $token) {
            if ($token->type === $type) {
                return $token;
            }
        }

        self::fail('No token of type ' . $type->value);
    }
}
```

- [ ] **Step 2: Run it, verify failure**

Run: `SKIP_SCHEMA_REBUILD=1 vendor/bin/phpunit tests/Unit/Service/Photo/Search/PhotoSearchQueryParserTest.php`
Expected: FAIL — `PhotoSearchQueryParser` not found.

- [ ] **Step 3: Create the parser**

`src/Service/Photo/Search/PhotoSearchQueryParser.php`. The `DICTIONARY` entries are `[phrase, PhotoAttributeType, canonical-values]`; multi-word phrases are keyed by their space-normalized form. It is scanned longest-phrase-first.

```php
<?php

declare(strict_types=1);

namespace App\Service\Photo\Search;

use App\Entity\PhotoAttributeType;
use App\Service\Photo\AttributeVocabulary;

final class PhotoSearchQueryParser
{
    private const int FUZZY_MIN_PREFIX = 3;

    private const int FUZZY_MAX_DISTANCE = 1;

    /**
     * Synonym dictionary: normalized phrase => [type, canonical vocabulary values].
     * Space-normalized (no '-' or '/'), lowercase. Scanned longest-phrase-first so
     * "long sleeve shirt" is consumed before the bare "shirt" fallback.
     *
     * @var array<string, array{PhotoAttributeType, list<string>}>
     */
    private const array DICTIONARY = [
        // Colours
        'black'  => [PhotoAttributeType::ClothingColor, ['black']],
        'white'  => [PhotoAttributeType::ClothingColor, ['white']],
        'grey'   => [PhotoAttributeType::ClothingColor, ['grey']],
        'gray'   => [PhotoAttributeType::ClothingColor, ['grey']],
        'red'    => [PhotoAttributeType::ClothingColor, ['red']],
        'orange' => [PhotoAttributeType::ClothingColor, ['orange']],
        'yellow' => [PhotoAttributeType::ClothingColor, ['yellow']],
        'green'  => [PhotoAttributeType::ClothingColor, ['green']],
        'blue'   => [PhotoAttributeType::ClothingColor, ['blue']],
        'purple' => [PhotoAttributeType::ClothingColor, ['purple']],
        'pink'   => [PhotoAttributeType::ClothingColor, ['pink']],
        'brown'  => [PhotoAttributeType::ClothingColor, ['brown']],
        'beige'  => [PhotoAttributeType::ClothingColor, ['beige']],

        // Garments
        't shirt'           => [PhotoAttributeType::ClothingType, ['t-shirt']],
        'tshirt'            => [PhotoAttributeType::ClothingType, ['t-shirt']],
        'tee'               => [PhotoAttributeType::ClothingType, ['t-shirt']],
        'tees'              => [PhotoAttributeType::ClothingType, ['t-shirt']],
        'long sleeve shirt' => [PhotoAttributeType::ClothingType, ['long-sleeve shirt']],
        'long sleeve'       => [PhotoAttributeType::ClothingType, ['long-sleeve shirt']],
        'longsleeve'        => [PhotoAttributeType::ClothingType, ['long-sleeve shirt']],
        'jacket'            => [PhotoAttributeType::ClothingType, ['jacket']],
        'coat'              => [PhotoAttributeType::ClothingType, ['jacket']],
        'hoodie sweater'    => [PhotoAttributeType::ClothingType, ['hoodie/sweater']],
        'hoodie'            => [PhotoAttributeType::ClothingType, ['hoodie/sweater']],
        'hoody'             => [PhotoAttributeType::ClothingType, ['hoodie/sweater']],
        'sweater'           => [PhotoAttributeType::ClothingType, ['hoodie/sweater']],
        'sweatshirt'        => [PhotoAttributeType::ClothingType, ['hoodie/sweater']],
        'jumper'            => [PhotoAttributeType::ClothingType, ['hoodie/sweater']],
        'dress'             => [PhotoAttributeType::ClothingType, ['dress']],
        'shorts'            => [PhotoAttributeType::ClothingType, ['shorts']],
        'short'             => [PhotoAttributeType::ClothingType, ['shorts']],
        'trousers'          => [PhotoAttributeType::ClothingType, ['trousers']],
        'trouser'           => [PhotoAttributeType::ClothingType, ['trousers']],
        'pants'             => [PhotoAttributeType::ClothingType, ['trousers']],
        'skirt'             => [PhotoAttributeType::ClothingType, ['skirt']],
        'hat cap'           => [PhotoAttributeType::ClothingType, ['hat/cap']],
        'hat'               => [PhotoAttributeType::ClothingType, ['hat/cap']],
        'cap'               => [PhotoAttributeType::ClothingType, ['hat/cap']],
        'shirt'             => [PhotoAttributeType::ClothingType, ['t-shirt', 'long-sleeve shirt']],

        // Scenes
        'start'              => [PhotoAttributeType::Scene, ['start']],
        'finish line'        => [PhotoAttributeType::Scene, ['finish-line']],
        'finishline'         => [PhotoAttributeType::Scene, ['finish-line']],
        'finish'             => [PhotoAttributeType::Scene, ['finish-line']],
        'on course running'  => [PhotoAttributeType::Scene, ['on-course/running']],
        'on course'          => [PhotoAttributeType::Scene, ['on-course/running']],
        'course'             => [PhotoAttributeType::Scene, ['on-course/running']],
        'running'            => [PhotoAttributeType::Scene, ['on-course/running']],
        'water station'      => [PhotoAttributeType::Scene, ['water-station']],
        'waterstation'       => [PhotoAttributeType::Scene, ['water-station']],
        'water'              => [PhotoAttributeType::Scene, ['water-station']],
        'crowd spectators'   => [PhotoAttributeType::Scene, ['crowd/spectators']],
        'crowd'              => [PhotoAttributeType::Scene, ['crowd/spectators']],
        'crowds'             => [PhotoAttributeType::Scene, ['crowd/spectators']],
        'spectators'         => [PhotoAttributeType::Scene, ['crowd/spectators']],
        'spectator'          => [PhotoAttributeType::Scene, ['crowd/spectators']],
        'medal podium'       => [PhotoAttributeType::Scene, ['medal/podium']],
        'medal'              => [PhotoAttributeType::Scene, ['medal/podium']],
        'medals'             => [PhotoAttributeType::Scene, ['medal/podium']],
        'podium'             => [PhotoAttributeType::Scene, ['medal/podium']],
    ];

    public function parse(string $q, bool $bibEnabled, bool $attributesEnabled): ParsedPhotoQuery
    {
        $words = $this->normalize($q);

        /** @var list<SearchToken> $tokens */
        $tokens = [];
        /** @var list<string> $ignored */
        $ignored = [];
        $bibTaken = false;

        $maxPhraseWords = $this->maxPhraseWords();
        $count          = count($words);
        $i              = 0;

        while ($i < $count) {
            $matched = false;

            if ($attributesEnabled) {
                for ($len = min($maxPhraseWords, $count - $i); $len >= 1; $len--) {
                    $phrase = implode(' ', array_slice($words, $i, $len));
                    if (isset(self::DICTIONARY[$phrase])) {
                        [$type, $values] = self::DICTIONARY[$phrase];
                        $tokens[]        = new SearchToken($type, $phrase, $values, $this->label($values));
                        $i              += $len;
                        $matched         = true;
                        break;
                    }
                }
            }

            if ($matched) {
                continue;
            }

            $word = $words[$i];
            $i++;

            if (preg_match('/^\d+$/', $word) === 1) {
                if ($bibEnabled && !$bibTaken) {
                    $tokens[] = new SearchToken(PhotoAttributeType::Bib, $word, [$word], 'bib ' . $word);
                    $bibTaken = true;
                } else {
                    $ignored[] = $word;
                }
                continue;
            }

            if ($attributesEnabled) {
                $fuzzy = $this->fuzzyColour($word);
                if ($fuzzy !== null) {
                    $tokens[] = new SearchToken(PhotoAttributeType::ClothingColor, $word, [$fuzzy], $fuzzy);
                    continue;
                }
            }

            $ignored[] = $word;
        }

        return new ParsedPhotoQuery($tokens, $ignored);
    }

    /**
     * @return list<string>
     */
    private function normalize(string $q): array
    {
        $lower      = mb_strtolower($q);
        $spaced     = str_replace(['-', '/'], ' ', $lower);
        $collapsed  = trim((string) preg_replace('/\s+/', ' ', $spaced));

        if ($collapsed === '') {
            return [];
        }

        return explode(' ', $collapsed);
    }

    private function maxPhraseWords(): int
    {
        $max = 1;
        foreach (array_keys(self::DICTIONARY) as $phrase) {
            $words = substr_count((string) $phrase, ' ') + 1;
            if ($words > $max) {
                $max = $words;
            }
        }

        return $max;
    }

    /**
     * @param list<string> $values
     */
    private function label(array $values): string
    {
        return count($values) === 1 ? $values[0] : implode(' or ', $values);
    }

    private function fuzzyColour(string $word): ?string
    {
        if (strlen($word) < self::FUZZY_MIN_PREFIX) {
            return null;
        }

        $matches = [];
        foreach (AttributeVocabulary::COLORS as $colour) {
            if (str_starts_with($colour, $word) || levenshtein($word, $colour) <= self::FUZZY_MAX_DISTANCE) {
                $matches[$colour] = true;
            }
        }

        $unique = array_keys($matches);

        return count($unique) === 1 ? $unique[0] : null;
    }
}
```

Note: the `testAmbiguousShirtMapsToBothShirts` test expects `label === 'shirt'`, but `label()` above produces `'t-shirt or long-sleeve shirt'` for multi-canonical tokens. Reconcile by making the ambiguous chip show the typed word: change the `label()` call for dictionary matches to pass the matched `$phrase` when there is more than one canonical:

```php
$label    = count($values) === 1 ? $values[0] : $phrase;
$tokens[] = new SearchToken($type, $phrase, $values, $label);
```
Replace the `$this->label($values)` call site with this inline `$label`, and delete the now-unused `label()` helper. (Update the test's expectation only if you deliberately choose the "or" label instead — but the spec says the chip shows the typed word for ambiguous terms, so prefer `$phrase`.)

- [ ] **Step 4: Run it, verify pass**

Run: `SKIP_SCHEMA_REBUILD=1 vendor/bin/phpunit tests/Unit/Service/Photo/Search/PhotoSearchQueryParserTest.php`
Expected: PASS. If `testFuzzyColourTypo` ('gren'→green) fails because `levenshtein('gren','grey')` and `levenshtein('gren','green')` both qualify, tighten by preferring the prefix/lowest-distance unique match: compute the minimum distance and keep it only if exactly one colour achieves it. Adjust `fuzzyColour` accordingly and re-run.

- [ ] **Step 5: Static analysis + magic-number + PSR-12**

Run: `vendor/bin/phpstan analyse src/Service/Photo/Search/PhotoSearchQueryParser.php tests/Unit/Service/Photo/Search/PhotoSearchQueryParserTest.php && vendor/bin/phpcs src/Service/Photo/Search/PhotoSearchQueryParser.php && vendor/bin/phpmnd src/Service/Photo/Search/`
Expected: all clean. (`FUZZY_MIN_PREFIX` / `FUZZY_MAX_DISTANCE` keep phpmnd happy.)

- [ ] **Step 6: Stage (do not commit)**

```bash
git add src/Service/Photo/Search/PhotoSearchQueryParser.php tests/Unit/Service/Photo/Search/PhotoSearchQueryParserTest.php
```
Proposed message: `117 - PhotoSearchQueryParser: synonym dictionary + fuzzy colour, longest-phrase-first tokenizer`

---

### Task 5: Controller wiring + template + functional tests

**Files:**
- Modify: `src/Controller/Public/EventController.php` (constructor; `photos`; replace `buildFilter`; `renderSearch`)
- Modify: `templates/public/event/photos.html.twig`
- Modify: `tests/Functional/Public/EventSearchTest.php`
- Modify: `tests/Functional/Public/EventFilterVisibilityTest.php`

**Interfaces:**
- Consumes: `PhotoSearchQueryParser::parse` (Task 4), `ParsedPhotoQuery::{isEmpty,toFilter,without,serialize}` (Task 3), `PhotoRepository::searchReady` (Task 2).
- Produces: `GET /e/{slug}/photos?q=<free text>` renders search results with chips + ignored list; `?q=` empty (or all-ignored) keeps the existing browse mode.

- [ ] **Step 1: Update the functional tests to the `q=` contract**

In `tests/Functional/Public/EventSearchTest.php`, replace the three test bodies:

```php
public function testColourFilterNarrowsGallery(): void
{
    $client = self::createClient();
    /** @var EntityManagerInterface $em */
    $em = self::getContainer()->get(EntityManagerInterface::class);

    $event  = PhotoFixtures::event($em, slug: 'run-2026');
    $orange = PhotoFixtures::readyPhoto($em, $event, '2026-07-15 10:00:00');
    $blue   = PhotoFixtures::readyPhoto($em, $event, '2026-07-15 10:01:00');
    PhotoFixtures::tagColour($em, $orange, 'orange');
    PhotoFixtures::tagColour($em, $blue, 'blue');
    $em->flush();

    $crawler = $client->request(Request::METHOD_GET, '/e/run-2026/photos?q=orange');

    $this->assertResponseIsSuccessful();
    $this->assertCount(1, $crawler->filter('[data-lightbox-target="trigger"]'));
    $this->assertCount(1, $crawler->filter('[data-testid="search-chip"]'));
}

public function testBibQueryIgnoredWhenToggleOff(): void
{
    $client = self::createClient();
    /** @var EntityManagerInterface $em */
    $em = self::getContainer()->get(EntityManagerInterface::class);

    $event = PhotoFixtures::event($em, slug: 'nobib-2026');
    $photo = PhotoFixtures::readyPhoto($em, $event, '2026-07-15 10:00:00');
    PhotoFixtures::tagBib($em, $photo, '1423');
    $em->flush();

    // Bib toggle off → the digits are ignored → no search tokens → browse mode,
    // which redirects to add ?t=. Proves the bib was NOT matched.
    $client->request(Request::METHOD_GET, '/e/nobib-2026/photos?q=1423');

    $this->assertResponseRedirects();
}

public function testBibQueryMatchesWhenToggleOn(): void
{
    $client = self::createClient();
    /** @var EntityManagerInterface $em */
    $em = self::getContainer()->get(EntityManagerInterface::class);

    $event = PhotoFixtures::event($em, slug: 'bib-2026', bibIndexing: true);
    $hit   = PhotoFixtures::readyPhoto($em, $event, '2026-07-15 10:00:00');
    $miss  = PhotoFixtures::readyPhoto($em, $event, '2026-07-15 10:01:00');
    PhotoFixtures::tagBib($em, $hit, '1423');
    PhotoFixtures::tagBib($em, $miss, '2000');
    $em->flush();

    $crawler = $client->request(Request::METHOD_GET, '/e/bib-2026/photos?q=1423');

    $this->assertResponseIsSuccessful();
    $this->assertCount(1, $crawler->filter('[data-lightbox-target="trigger"]'));
    $this->assertSelectorTextContains('[data-testid="search-chip"]', 'bib 1423');
}
```

In `tests/Functional/Public/EventFilterVisibilityTest.php`, change both `data-testid="attribute-filter"` selectors to `data-testid="photo-search"` (the box replaces the old filter form; visibility gating is unchanged — hidden with no attributes + bib off, shown when attribute data exists).

- [ ] **Step 2: Run the functional tests, verify they fail**

Run: `vendor/bin/phpunit tests/Functional/Public/EventSearchTest.php tests/Functional/Public/EventFilterVisibilityTest.php`
Expected: FAIL — old markup/params gone or new selectors absent.

- [ ] **Step 3: Wire the controller**

In `src/Controller/Public/EventController.php`:

(a) Add the parser to the constructor (after the existing promoted properties):

```php
        private readonly \App\Service\Photo\Search\PhotoSearchQueryParser $searchParser,
```
Add `use App\Service\Photo\Search\ParsedPhotoQuery;` and `use App\Service\Photo\Search\PhotoSearchQueryParser;` to the imports (and reference `PhotoSearchQueryParser` in the constructor without the FQN once imported).

(b) Replace the top of `photos()` (the block from the `w` guard through the `if (!$filter->isEmpty())` return) with:

```php
        if ($request->query->has('w')) {
            throw new BadRequestHttpException('Window is no longer configurable per request.');
        }

        $hasAttributes = $this->photoAttributes->eventHasAttributes($event);
        $q             = trim((string) $request->query->get('q', ''));
        $parsed        = $this->searchParser->parse($q, $event->isBibIndexingEnabled(), $hasAttributes);

        if (!$parsed->isEmpty()) {
            return $this->renderSearch($event, $parsed, $q, $hasAttributes, $request);
        }
```

(c) In the browse-mode `render(...)` call at the end of `photos()`, replace the `'filter' => new PhotoAttributeFilter(),` line and the two `allowed*` lines. The browse render must still pass `filterAvailable` and `bibSearchEnabled`; add `'parsedQuery'` and `'q'`, and drop `filter`, `allowedColours`, `allowedGarments`:

```php
            'searchMode'       => false,
            'parsedQuery'      => new ParsedPhotoQuery([], []),
            'q'                => '',
            'filterAvailable'  => $hasAttributes,
            'bibSearchEnabled' => $event->isBibIndexingEnabled(),
```

(d) Delete the `buildFilter` and `allowedValues` private methods (parsing now lives in the service). Remove the `PhotoAttributeFilter` and `AttributeVocabulary` imports if nothing else references them (Step 6 phpcs will confirm).

(e) Replace `renderSearch` with the parsed-query version:

```php
    private function renderSearch(
        Event $event,
        ParsedPhotoQuery $parsed,
        string $q,
        bool $hasAttributes,
        Request $request,
    ): Response {
        $filter        = $parsed->toFilter();
        $lastUpdatedAt = $this->photos->lastReadyUpdatedAtForEvent($event);
        $readyCount    = $this->photos->countReady($event);
        $etag          = sha1(sprintf(
            '%d|search|%s|%s|%s|%s|%s|%d',
            (int) $event->getId(),
            implode(',', $filter->colours),
            implode(',', $filter->garments),
            implode(',', $filter->scenes),
            $filter->bib ?? '-',
            $lastUpdatedAt instanceof DateTimeImmutable ? $lastUpdatedAt->format('U.u') : '-',
            $readyCount,
        ));

        $response = new Response();
        $response->setEtag($etag);
        $response->setPublic();
        $response->setMaxAge(0);
        $response->headers->addCacheControlDirective('must-revalidate');

        if ($response->isNotModified($request)) {
            return $response;
        }

        $photos = $this->photos->searchReady($event, $filter, self::HARD_CAP);

        return $this->render('public/event/photos.html.twig', [
            'event'            => $event,
            'searchMode'       => true,
            'parsedQuery'      => $parsed,
            'q'                => $q,
            'filterAvailable'  => $hasAttributes,
            'photos'           => $photos,
            'capHit'           => count($photos) === self::HARD_CAP,
            'firstRank'        => 1,
            'totalReady'       => count($photos),
            'bibSearchEnabled' => $event->isBibIndexingEnabled(),
            'resolvedStyle'    => $this->styleResolver->resolve($event),
            'brand'            => $this->brandResolver->resolve($event),
        ], $response);
    }
```

- [ ] **Step 4: Rewrite the template search UI**

In `templates/public/event/photos.html.twig`, replace the entire `{% if filterAvailable %} ... {% endif %}` block (the attribute-filter form, lines ~14-55) with the single box + chips:

```twig
            {% if filterAvailable or bibSearchEnabled %}
            <div class="space-y-2">
                <form method="get"
                      action="{{ path('public_event_photos', {slug: event.slug}) }}"
                      data-testid="photo-search"
                      class="flex flex-wrap items-center gap-2 text-sm">
                    <label for="photo-q" class="sr-only">Search photos</label>
                    <input type="search"
                           id="photo-q"
                           name="q"
                           value="{{ q }}"
                           data-testid="search-input"
                           placeholder="{{ (filterAvailable and bibSearchEnabled) ? 'e.g. 1234, red, t-shirt, finish line' : (bibSearchEnabled ? 'e.g. 1234' : 'e.g. red, t-shirt, finish line') }}"
                           class="input input-sm input-bordered w-full max-w-md">
                    <button type="submit" class="btn btn-sm btn-primary">Search</button>
                    {% if searchMode %}
                        <a href="{{ path('public_event_photos', {slug: event.slug}) }}"
                           class="btn btn-sm btn-ghost">Clear</a>
                    {% endif %}
                </form>

                <p class="text-xs text-base-content/60">
                    Search by
                    {%- if bibSearchEnabled %} bib number{% if filterAvailable %},{% endif %}{% endif -%}
                    {%- if filterAvailable %} colour, garment, or scene{% endif -%}.
                </p>

                {% if searchMode and parsedQuery.tokens is not empty %}
                    <div class="flex flex-wrap gap-2" data-testid="search-chips">
                        {% for token in parsedQuery.tokens %}
                            <span class="badge badge-outline gap-1" data-testid="search-chip">
                                {{ token.label }}
                                <a href="{{ path('public_event_photos', {slug: event.slug, q: parsedQuery.without(loop.index0).serialize()}) }}"
                                   data-testid="chip-remove"
                                   aria-label="Remove {{ token.label }}"
                                   class="font-semibold">×</a>
                            </span>
                        {% endfor %}
                    </div>
                {% endif %}

                {% if searchMode and parsedQuery.ignored is not empty %}
                    <p class="text-xs text-base-content/50" data-testid="search-ignored">
                        Ignored: {{ parsedQuery.ignored|join(', ') }}
                    </p>
                {% endif %}
            </div>
            {% endif %}
```

The `{% if not searchMode %}` time-form + nav block and the two count paragraphs below it stay exactly as they are.

- [ ] **Step 5: Run the functional tests, verify pass**

Run: `vendor/bin/phpunit tests/Functional/Public/EventSearchTest.php tests/Functional/Public/EventFilterVisibilityTest.php`
Expected: PASS.

- [ ] **Step 6: Static analysis + PSR-12 on the controller**

Run: `vendor/bin/phpstan analyse src/Controller/Public/EventController.php && vendor/bin/phpcs src/Controller/Public/EventController.php`
Expected: clean. Fix any unused-import errors (`PhotoAttributeFilter`, `AttributeVocabulary`) by removing those `use` lines. Prefer importing `PhotoSearchQueryParser`/`ParsedPhotoQuery` over inline FQNs.

- [ ] **Step 7: Full suite + schema validate**

Run: `vendor/bin/phpunit && bin/console doctrine:schema:validate --skip-sync`
Expected: green suite; mapping validates (no schema change in this feature, so this should already pass — the command guards against accidental drift).

- [ ] **Step 8: Stage (do not commit)**

```bash
git add src/Controller/Public/EventController.php templates/public/event/photos.html.twig tests/Functional/Public/EventSearchTest.php tests/Functional/Public/EventFilterVisibilityTest.php
```
Proposed message: `117 - unified photo search box: q= parser wiring, chips + ignored UI, replace checkbox controls`

---

### Task 6: Full gate + manual verification

**Files:** none (verification only).

- [ ] **Step 1: Run the whole quality gate**

Run: `vendor/bin/grumphp run`
Expected: all tasks pass (phpstan, phpcs, phpmnd, phpcpd, rector, securitychecker, phpunit, schema validate). Fix anything that trips; re-run until green.

- [ ] **Step 2: Manual smoke test (use the `verify` skill)**

Bring up the stack (`docker compose up -d`), open an event that has attributes + bib indexing, and exercise:
- `?q=1423 red shirt` → chips `bib 1423`, `red`, `shirt`; results are the union.
- Remove the `red` chip → URL loses `red`, results widen.
- `?q=finish line` → scene chip, finish-line photos.
- `?q=banana` → browse redirect (no tokens) / `?q=red banana` → `red` chip + `Ignored: banana`.
- Event with bib indexing OFF → typing `1423` does not surface bib matches; box still shows for colour/garment.

- [ ] **Step 3: Report**

Summarize results and the six proposed commit messages (one per task) for the user to commit.

---

## Self-Review

**Spec coverage:**
- Single `q=` box replacing three controls → Task 5 (controller + template). ✓
- Bib ∪ (colour ∩ garment ∩ scene) semantics → Task 2. ✓
- Scenes searchable → Tasks 1, 2, 4. ✓
- Synonyms + multi-word phrases + fuzzy colour + ambiguous `shirt` → Task 4. ✓
- Chips with plain-link removal + ignored feedback → Tasks 3 (serialize/without) + 5 (template). ✓
- Discoverability placeholder/helper + gating (bib vs attributes) → Tasks 4 (parser gating) + 5 (template/controller). ✓
- Bib-suppression preserved, digits-only bib, first-number-wins → Tasks 2 + 4. ✓
- Drop old `colour[]/garment[]/bib` params → Task 5 (buildFilter/allowedValues deleted, tests updated). ✓
- Tests: parser unit, filter unit, repo integration, functional → Tasks 1-5. ✓

**Placeholder scan:** No TBD/TODO; every code step shows full code. Two reconciliation notes (parser `label` for ambiguous `shirt`; fuzzy tie-break) are explicit with the exact fix, not deferred.

**Type consistency:** `PhotoSearchQueryParser::parse(string, bool, bool): ParsedPhotoQuery`, `ParsedPhotoQuery::{isEmpty,toFilter,without,serialize}`, `SearchToken(type,sourceText,canonicals,label)`, `PhotoAttributeFilter(colours,garments,bib,scenes)` used identically across Tasks 3-5. Repository `searchReady(Event,PhotoAttributeFilter,int): list<Photo>` unchanged signature. Consistent.
