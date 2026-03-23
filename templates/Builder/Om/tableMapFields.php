
    /**
     * holds an array of fieldnames
     *
     * first dimension keys are the type constants
     * e.g. self::$fieldNames[self::TYPE_PHPNAME][0] = 'Id'
     *
     * @var array<string, mixed>
     */
    protected static array $fieldNames = [
        self::TYPE_PHPNAME       => [<?= $fieldNamesPhpName ?>],
        self::TYPE_CAMELNAME     => [<?= $fieldNamesCamelCaseName ?>],
        self::TYPE_COLNAME       => <?= $fieldNamesColname ?>,
        self::TYPE_FIELDNAME     => [<?= $fieldNamesFieldName ?>]
    ];
