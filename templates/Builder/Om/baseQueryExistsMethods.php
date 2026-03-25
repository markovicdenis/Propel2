    
    /**
     * Use the <?= $relationDescription ?> for an EXISTS query.
     *
     * @see \Propel\Runtime\ActiveQuery\ModelCriteria::useExistsQuery()
     *
     * @template TQuery of ModelCriteria
     *
     * @psalm-param \Propel\Runtime\ActiveQuery\Criterion\ExistsQueryCriterion::TYPE_* $typeOfExists
     * @psalm-param class-string<TQuery>|null $queryClass
     * @psalm-return ($queryClass is null ? <?= $queryClass ?> : TQuery)
     *
     * @phpstan-param class-string<TQuery>|null $queryClass
     * @phpstan-return ($queryClass is null ? <?= $queryClass ?> : TQuery)
     *
     * @param string|null $modelAlias sets an alias for the nested query
     * @param class-string<ModelCriteria>|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     * @param string $typeOfExists Either ExistsQueryCriterion::TYPE_EXISTS or ExistsQueryCriterion::TYPE_NOT_EXISTS
     *
     * @return <?= $queryClass ?>|ModelCriteria The inner query object of the EXISTS statement
     */
    public function use<?= $relationName ?>ExistsQuery(?string $modelAlias = null, ?string $queryClass = null, string $typeOfExists = '<?= $existsType ?>'): ModelCriteria
    {
        /** @var <?= $queryClass ?>|TQuery $q */
        $q = $this->useExistsQuery('<?= $relationName ?>', $modelAlias, $queryClass, $typeOfExists);
        return $q;
    }

    /**
     * Use the <?= $relationDescription ?> for a NOT EXISTS query.
     *
     * @see use<?= $relationName ?>ExistsQuery()
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
     * @param class-string<ModelCriteria>|null $queryClass Allows to use a custom query class for the exists query, like ExtendedBookQuery::class
     *
     * @return <?= $queryClass ?>|ModelCriteria The inner query object of the NOT EXISTS statement
     */
    public function use<?= $relationName ?>NotExistsQuery(?string $modelAlias = null, ?string $queryClass = null): ModelCriteria
    {
        /** @var <?= $queryClass ?>|TQuery $q */
        $q = $this->useExistsQuery('<?= $relationName ?>', $modelAlias, $queryClass, '<?= $notExistsType ?>');
        return $q;
    }
