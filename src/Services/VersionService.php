<?php

namespace Debug\AiHealth\Services;

use Composer\Semver\Semver;

/**
 * Pure version maths over a package's released tags using composer/semver:
 *  - which version a Composer constraint (e.g. "^1.27") would install, and
 *  - which version supports a given Laravel version.
 */
class VersionService
{
    /**
     * Resolve a Composer constraint to the newest STABLE version that satisfies
     * it (falling back to the newest matching pre-release if there is no stable
     * match). Returns null when nothing satisfies the constraint.
     *
     * @param  array<int, string>  $versions
     */
    public function resolveConstraint(array $versions, string $constraint): ?string
    {
        $matching = $this->safeSatisfiedBy($versions, $constraint);

        if (empty($matching)) {
            return null;
        }

        $stable = array_filter($matching, fn ($v) => $this->isStable($v));

        $pool = Semver::rsort($stable ?: $matching);

        return $pool[0] ?? null;
    }

    /**
     * How many released versions satisfy the constraint.
     *
     * @param  array<int, string>  $versions
     */
    public function countMatching(array $versions, string $constraint): int
    {
        return count($this->safeSatisfiedBy($versions, $constraint));
    }

    /**
     * Given a map of version => [dependency => constraint], find the newest
     * STABLE version whose Laravel/Illuminate constraints allow $laravelVersion.
     * A version with no Laravel constraint is treated as framework-agnostic
     * (compatible). Returns null when no version supports it.
     *
     * @param  array<string, array<string, string>>  $versionConstraints
     */
    public function bestVersionForLaravel(array $versionConstraints, string $laravelVersion): ?string
    {
        $target = $this->normalize($laravelVersion);

        $compatible = [];

        foreach ($versionConstraints as $version => $constraints) {
            if (! $this->isStable((string) $version)) {
                continue;
            }

            if ($this->constraintsAllow($constraints, $target)) {
                $compatible[] = (string) $version;
            }
        }

        if (empty($compatible)) {
            return null;
        }

        return Semver::rsort($compatible)[0] ?? null;
    }

    /**
     * Does a single version's constraint map allow the target Laravel version?
     * Every declared Laravel/Illuminate constraint must be satisfied.
     *
     * @param  array<string, string>  $constraints
     */
    public function constraintsAllow(array $constraints, string $target): bool
    {
        if (empty($constraints)) {
            return true; // framework-agnostic
        }

        foreach ($constraints as $range) {
            if (! $this->safeSatisfies($target, $range)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Turn "11" or "10.4" into a full "x.y.z" Semver can compare.
     */
    public function normalize(string $version): string
    {
        $parts = explode('.', ltrim(trim($version), 'vV'));

        while (count($parts) < 3) {
            $parts[] = '0';
        }

        return implode('.', array_slice($parts, 0, 3));
    }

    protected function isStable(string $version): bool
    {
        $normalized = ltrim($version, 'vV');

        if (str_starts_with(strtolower($normalized), 'dev-') || str_ends_with(strtolower($normalized), '-dev')) {
            return false;
        }

        return ! preg_match('/-(alpha|beta|rc|pre)/i', $normalized);
    }

    /**
     * @param  array<int, string>  $versions
     * @return array<int, string>
     */
    protected function safeSatisfiedBy(array $versions, string $constraint): array
    {
        try {
            return Semver::satisfiedBy($versions, $constraint);
        } catch (\Throwable) {
            return [];
        }
    }

    protected function safeSatisfies(string $version, string $constraint): bool
    {
        try {
            return Semver::satisfies($version, $constraint);
        } catch (\Throwable) {
            return false;
        }
    }
}
