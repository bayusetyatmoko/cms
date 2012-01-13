<?php

/**
 * @abstract
 */
abstract class BaseModel extends CActiveRecord
{
	protected $attributes = array();
	protected $belongsTo = array();
	protected $hasBlocks = array();
	protected $hasContent = array();
	protected $hasMany = array();
	protected $hasOne = array();
	protected $_tableName;

	/**
	 * @param bool $names
	 * @return string The model's table name
	 */
	public function tableName()
	{
		return '{{'.$this->tableName.'}}';
	}

	/**
	 * Get the model's table name (without the curly brackets)
	 * @return string The table name
	 * @access protected
	 */
	protected function getTableName()
	{
		if (!isset($this->_tableName))
			$this->_tableName = strtolower(get_class($this));

		return $this->_tableName;
	}

	/**
	 * @return array Validation rules for model's attributes
	 */
	public function rules()
	{
		$required = array();
		$integers = array();
		$maxSizes = array();

		$defaultAttributeSettings = array('type' => AttributeType::String, 'maxSize' => 150, 'required' => false);

		foreach ($this->attributes as $attributeName => $attributeSettings)
		{
			$attributeSettings = array_merge($defaultAttributeSettings, $attributeSettings);

			if ($attributeSettings['required'] === true)
				$required[] = $attributeName;

			if ($attributeSettings['type'] == AttributeType::Integer)
				$integers[] = $attributeName;

			if ($attributeSettings['type'] == AttributeType::String)
				$maxSizes[(string)$attributeName['maxSize']][] = $attributeName;
		}

		$rules = array();

		if ($required)
			$rules[] = array(implode(', ', $required), 'required');

		if ($integers)
			$rules[] = array(implode(', ', $integers), 'numerical', 'integerOnly' => true);

		if ($maxSizes)
		{
			foreach ($maxSizes as $maxSize => $attributeNames)
			{
				$rules[] = array(implode(', ', $attributeNames), 'length', 'max' => (int)$maxSize);
			}
		}

		$rules[] = array(implode(', ', array_keys($this->attributes)), 'safe', 'on' => 'search');

		return $rules;
	}

	/**
	 * @return array Relational rules
	 */
	public function relations()
	{
		$relations = array();

		foreach ($this->hasBlocks as $key => $settings)
		{
			$relations[$key] = $this->generateJoinThroughRelation('ContentBlocks', 'block_id', $settings);
		}

		foreach ($this->hasContent as $key => $settings)
		{
			$relations[$key] = $this->generateJoinThroughRelation('Content', 'content_id', $settings);
		}

		foreach ($this->hasMany as $key => $settings)
		{
			$relations[$key] = $this->generateHasXRelation(self::HAS_MANY, $settings);
		}

		foreach ($this->hasOne as $key => $model)
		{
			$relations[$key] = $this->generateHasXRelation(self::HAS_ONE, $settings);
		}

		foreach ($this->belongsTo as $key => $model)
		{
			$relations[$key] = array(self::BELONGS_TO, $model, $key.'_id');
		}

		return $relations;
	}

	/**
	 * Generates HAS_MANY relations to a model through another model
	 * @access protected
	 * @param string $model The destination model
	 * @param string $fk2 The join table's foreign key to the destination model
	 * @param array $settings The initial model's settings for the relation
	 * @return The CActiveRecord relation
	 */
	protected function generateJoinThroughRelation($model, $fk2, $settings)
	{
		return array(self::HAS_MANY, $model, array($settings['foreignKey'].'_id' => $fk2), 'through' => $settings['through']);
	}

	/**
	 * Generates HAS_MANY and HAS_ONE relations
	 * @access protected
	 * @param string $relationType The type of relation to generate (self::HAS_MANY or self::HAS_ONE)
	 * @param array $settings The relation settings
	 * @return array The CActiveRecord relation
	 */
	protected function generateHasXRelation($relationType, $settings)
	{
		if (is_array($settings['foreignKey']))
		{
			$fk = array();
			foreach ($settings['foreignKey'] as $fk1 => $fk2)
			{
				$fk[$fk1.'_id'] = $fk2.'_id';
			}
		}
		else
		{
			$fk = $settings['foreignKey'].'_id';
		}

		$relation = array($relationType, $settings['model'], $fk);

		if (isset($settings['through']))
			$relation['through'] = $settings['through'];

		return $relation;
	}

	/**
	 * Retrieves a list of models based on the current search/filter conditions.
	 * @return CActiveDataProvider The data provider that can return the models based on the search/filter conditions.
	 */
	public function search()
	{
		// Warning: Please modify the following code to remove attributes that
		// should not be searched.

		$criteria = new CDbCriteria;

		foreach ($this->attributes as $attributeName => $attributeSettings)
		{
			$criteria->compare($attributeName, $this->$attributeName);
		}

		return new CActiveDataProvider($this, array(
			'criteria' => $criteria
		));
	}

	/**
	 * Creates the model's table
	 */
	public function createTable()
	{
		$connection = Blocks::app()->db;

		// make sure that the table doesn't already exist
		if ($connection->schema->getTable('{{'.$this->tableName.'}}') !== null)
			throw new BlocksException($this->tableName.' already exists.');

		$columns['id'] = AttributeType::PK;

		foreach ($this->belongsTo as $name => $settings)
		{
			$required = isset($settings['required']) ? $settings['required'] : false;
			$settings = array('type' => AttributeType::Integer, 'required' => $required);
			$columns[$name.'_id'] = DatabaseHelper::generateColumnDefinition($settings);
		}

		foreach ($this->attributes as $name => $settings)
		{
			$columns[$name] = DatabaseHelper::generateColumnDefinition($settings);
		}

		$columns['date_created'] = DatabaseHelper::generateColumnDefinition(array('type' => AttributeType::Integer, 'required' => true));
		$columns['date_updated'] = DatabaseHelper::generateColumnDefinition(array('type' => AttributeType::Integer, 'required' => true));
		$columns['uid']          = DatabaseHelper::generateColumnDefinition(array('type' => AttributeType::String, 'maxLength' => 36, 'required' => true));

		// start the transaction
		$transaction = $connection->beginTransaction();
		try
		{
			// create the table
			$connection->createCommand()->createTable('{{'.$this->tableName.'}}', $columns);

			// add the insert and update triggers
			DatabaseHelper::createInsertAuditTrigger($this->tableName);
			DatabaseHelper::createUpdateAuditTrigger($this->tableName);
		}
		catch (Exception $e)
		{
			$transaction->rollBack();
			throw $e;
		}
	}

	/**
	 * Adds foreign keys to the model's table
	 */
	public function addForeignKeys()
	{
		$connection = Blocks::app()->db;

		// start the transaction
		$transaction = $connection->beginTransaction();
		try
		{
			foreach ($this->belongsTo as $name => $settings)
			{
				$otherTableName = strtolower($settings['model']);
				$fkName = $this->tableName.'_'.$otherTableName.'_fk';
				$connection->createCommand()->addForeignKey($fkName, '{{'.$this->tableName.'}}', $name.'_id', '{{'.$otherTableName.'}}', 'id');
			}
		}
		catch (Exception $e)
		{
			$transaction->rollBack();
			throw $e;
		}
	}

	/**
	 * Drops the foreign keys from the model's table
	 */
	public function dropForeignKeys()
	{
		$connection = Blocks::app()->db;

		// start the transaction
		$transaction = $connection->beginTransaction();
		try
		{
			foreach ($this->belongsTo as $name => $settings)
			{
				$otherTableName = strtolower($settings['model']);
				$fkName = $this->tableName.'_'.$otherTableName.'_fk';
				$connection->createCommand()->dropForeignKey($fkName, '{{'.$this->tableName.'}}');
			}
		}
		catch (Exception $e)
		{
			$transaction->rollBack();
			throw $e;
		}
	}

	/**
	 * Drops the model's table
	 */
	public function dropTable()
	{
		$connection = Blocks::app()->db;

		if ($connection->schema->getTable($this->tableName) !== null)
		{
			$connection->createCommand()->dropTable($this->tableName);
		}
	}

	/**
	 * Returns an instance of the specified model
	 * @return object The model instance
	 * @static
	 */
	public static function model($class = __CLASS__)
	{
		return parent::model($class);
	}
}
