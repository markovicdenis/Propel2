
    /**
     * Use the <?= $relationDescription ?> for an IN query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useInQuery()
     *
     * @template TQuery of ModelCriteria
     *
     * @psalm-param \Propel\Runtime\ActiveQuery\Criteria::*IN $typeOfIn
     * @psalm-param class-string<TQuery>|null $queryClass
     * @psalm-return ($queryClass is null ? <?= $queryClass ?> : TQuery)
     *
     * @phpstan-param class-string<TQuery>|null $queryClass
     * @phpstan-return ($queryClass is null ? <?= $queryClass ?> : TQuery)
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param class-string<ModelCriteria>|null $queryClass Allows to use a custom query class for the IN query, like ExtendedBookQuery::class
     * @param string $typeOfIn Criteria::IN or Criteria::NOT_IN
     *
     * @return <?= $queryClass ?>|ModelCriteria The inner query object of the IN statement
     */
    public function useIn<?= $relationName ?>Query(?string $modelAlias = null, ?string $queryClass = null, string $typeOfIn = '<?= $inType ?>'): ModelCriteria
    {
        /** @var <?= $queryClass ?>|TQuery $q */
        $q = $this->useInQuery('<?= $relationName ?>', $modelAlias, $queryClass, $typeOfIn);
        return $q;
    }

    /**
     * Use the <?= $relationDescription ?> for a NOT IN query.
     *
     * @see use<?= $relationName ?>InQuery()
     *
     * @template TQuery of ModelCriteria
     *
     * @psalm-param class-string<TQuery>|null $queryClass
     * @psalm-return ($queryClass is null ? <?= $queryClass ?> : TQuery)
     *
     * @phpstan-param class-string<TQuery>|null $queryClass
     * @phpstan-return ($queryClass is null ? <?= $queryClass ?> : TQuery)
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param class-string<ModelCriteria>|null $queryClass Allows to use a custom query class for the NOT IN query, like ExtendedBookQuery::class
     *
     * @return <?= $queryClass ?>|ModelCriteria The inner query object of the NOT IN statement
     */
    public function useNotIn<?= $relationName ?>Query(?string $modelAlias = null, ?string $queryClass = null): ModelCriteria
    {
        /** @var <?= $queryClass ?>|TQuery $q */
        $q = $this->useInQuery('<?= $relationName ?>', $modelAlias, $queryClass, '<?= $notInType ?>');
        return $q;
    }
