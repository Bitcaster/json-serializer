<?php

namespace Zumba\JsonSerializer;

use Closure;
use DateTimeInterface;
use InvalidArgumentException;
use JsonException;
use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use ReflectionClass;
use ReflectionException;
use RuntimeException;
use SplDoublyLinkedList;
use SplObjectStorage;
use SuperClosure\SerializerInterface as ClosureSerializerInterface;
use UnitEnum;
use Zumba\JsonSerializer\Exception\JsonSerializerException;

class JsonSerializer
{

    public const string CLASS_IDENTIFIER_KEY = '@type';
    public const string CLOSURE_IDENTIFIER_KEY = '@closure';
    public const string UTF8ENCODED_IDENTIFIER_KEY = '@utf8encoded';
    public const string SCALAR_IDENTIFIER_KEY = '@scalar';
    public const string FLOAT_ADAPTER = 'JsonSerializerFloatAdapter';

    public const int KEY_UTF8ENCODED = 1;
    public const int VALUE_UTF8ENCODED = 2;

    public const int UNDECLARED_PROPERTY_MODE_SET = 1;
    public const int UNDECLARED_PROPERTY_MODE_IGNORE = 2;
    public const int UNDECLARED_PROPERTY_MODE_EXCEPTION = 3;

    /**
     * Storage for object
     *
     * Used for recursion
     *
     * @var SplObjectStorage
     */
    protected SplObjectStorage $objectStorage;

    /**
     * Object mapping for recursion
     *
     * @var array
     */
    protected array $objectMapping = [];

    /**
     * Object mapping index
     *
     * @var integer
     */
    protected int $objectMappingIndex = 0;

    /**
     * Closure manager
     *
     * @var ClosureSerializer\ClosureSerializerManager
     */
    protected ClosureSerializer\ClosureSerializerManager $closureManager;

    /**
     * Map of custom object serializers
     *
     * @var array
     */
    protected array $customObjectSerializerMap;

    /**
     * Undefined Attribute Mode
     *
     * @var integer
     */
    protected int $undefinedAttributeMode = self::UNDECLARED_PROPERTY_MODE_SET;

    protected array $notAllowedProperties =
        [
            'connection',
            'resolver'
        ];

    /**
     * Constructor.
     *
     * @param ClosureSerializerInterface|null $closureSerializer This parameter is deprecated and will be removed in 5.0.0. Use addClosureSerializer() instead.
     * @param array $customObjectSerializerMap
     */
    public function __construct(
        ?ClosureSerializerInterface $closureSerializer = null,
        array $customObjectSerializerMap = []
    ) {
        $this->closureManager = new ClosureSerializer\ClosureSerializerManager();
        if ($closureSerializer) {
            trigger_error(
                'Passing a ClosureSerializerInterface to the constructor is deprecated and will be removed in 4.0.0. Use addClosureSerializer() instead.',
                E_USER_DEPRECATED
            );
            $this->addClosureSerializer(new ClosureSerializer\SuperClosureSerializer($closureSerializer));
        }

        $this->customObjectSerializerMap = (array)$customObjectSerializerMap;
    }

    /**
     * Add a closure serializer
     */
    public function addClosureSerializer(ClosureSerializer\ClosureSerializer $closureSerializer): void
    {
        $this->closureManager->addSerializer($closureSerializer);
    }

    /**
     * Serialize the value in JSON
     *
     * @param mixed $value
     *
     * @return string JSON encoded
     * @throws JsonSerializerException
     */
    public function serialize(mixed $value): string
    {
        $this->reset();
        $serializedData = $this->serializeData($value);
        try {
            $encoded = json_encode($serializedData, JSON_THROW_ON_ERROR | $this->calculateEncodeOptions());
        } catch (JsonException $e) {
            if ($e->getCode() !== JSON_ERROR_UTF8) {
                throw new JsonSerializerException('Invalid data to encode to JSON. Error: ' . $e->getMessage() . '(Code ' . $e->getCode() . ')');
            }
            $serializedData = $this->encodeNonUtf8ToUtf8($serializedData);
            try {
                $encoded = json_encode($serializedData, JSON_THROW_ON_ERROR | $this->calculateEncodeOptions());
            } catch (JsonException $e) {
                if ($encoded === false || $e->getCode() !== JSON_ERROR_NONE) {
                    throw new JsonSerializerException('Invalid data to encode to JSON. Error: ' . $e->getMessage() . '(Code ' . $e->getCode() . ')');
                }
            }
        }

        return $this->processEncodedValue($encoded);
    }

    /**
     * Reset variables
     *
     * @return void
     */
    protected function reset(): void
    {
        $this->objectStorage = new SplObjectStorage();
        $this->objectMapping = [];
        $this->objectMappingIndex = 0;
    }

    /**
     * Parse the data to be json encoded
     *
     * @param mixed $value
     *
     * @return mixed
     * @throws JsonSerializerException
     */
    protected function serializeData(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }
        if (is_resource($value)) {
            throw new JsonSerializerException('Resource is not supported in JsonSerializer');
        }
        if (is_array($value)) {
            return array_map([$this, __FUNCTION__], $value);
        }
        if ($value instanceof Closure) {
            $closureSerializer = $this->closureManager->getPreferredSerializer();
            if (!$closureSerializer) {
                throw new JsonSerializerException('Closure serializer not given. Unable to serialize closure.');
            }

            return [
                static::CLOSURE_IDENTIFIER_KEY => true,
                // Keep BC compat to PHP 7: Don't use "::class" on dynamic class names
                'serializer' => get_class($closureSerializer),
                'value' => $closureSerializer->serialize($value)
            ];
        }

        return $this->serializeObject($value);
    }

    /**
     * Extract the data from an object
     *
     * @param object $value
     *
     * @return array
     */
    protected function serializeObject(object $value): array
    {
        if ($this->objectStorage->contains($value)) {
            return [static::CLASS_IDENTIFIER_KEY => '@' . $this->objectStorage[$value]];
        }
        $this->objectStorage->attach($value, $this->objectMappingIndex++);

        $ref = new ReflectionClass($value);
        $className = $ref->getName();
        $data = [static::CLASS_IDENTIFIER_KEY => $className];
        if (array_key_exists($className, $this->customObjectSerializerMap)) {
            $data += $this->customObjectSerializerMap[$className]->serialize($value);

            return $data;
        }

        if ($value instanceof DateTimeInterface) {
            return $data + (array)$value;
        }

        if ($value instanceof SplDoublyLinkedList) {
            return $data + ['value' => $value->serialize()];
        }

        $paramsToSerialize = $this->getObjectProperties($ref, $value);
        $data += array_map([$this, 'serializeData'], $this->extractObjectData($value, $ref, $paramsToSerialize));

        return $data;
    }

    /**
     * Return the list of properties to be serialized
     *
     * @param ReflectionClass $ref
     * @param object $value
     *
     * @return array
     */
    protected function getObjectProperties(ReflectionClass $ref, object $value): array
    {
        if (method_exists($value, '__sleep')) {
            return $value->__sleep();
        }

        $props = [];
        foreach ($ref->getProperties() as $prop) {
            $props[] = $prop->getName();
        }

        return array_unique(array_merge($props, array_keys(get_object_vars($value))));
    }

    /**
     * Extract the object data
     *
     * @param object $value
     * @param ReflectionClass $ref
     * @param array $properties
     *
     * @return array
     */
    protected function extractObjectData(object $value, ReflectionClass $ref, array $properties): array
    {
        $data = [];
        foreach ($properties as $property) {
            if (in_array($property, $this->getNotAllowedProperties(), true)) {
                continue;
            }
            try {
                if ($ref->hasProperty($property)) {
                    $propRef = $ref->getProperty($property);
                    $propRef->setAccessible(true);
                    $data[$property] = $propRef->getValue($value);
                } elseif (method_exists($value, $property)) {
                    $data[$property] = $value->$property;
                } elseif ($value instanceof UTCDateTime) {
                    $timestamp = $value->toDateTime()->getTimestamp();
                    if (strlen((string)$timestamp) !== 13 && !empty($timestamp) && $timestamp != 0) {
                        $timestamp = (int)$timestamp * 1000;
                    }
                    /** @var UTCDateTime $value */
                    $data[$property] = $timestamp;
                } elseif ($value instanceof ObjectId) {
                    $data[$property] = (string)$value;
                } else {
                    $propRef = $ref->getProperty($property);
                    $propRef->setAccessible(true);
                    $data[$property] = $propRef->getValue($value);
                }
            } catch (ReflectionException $e) {
                $data[$property] = $value->$property;
            }
        }

        return $data;
    }

    /**
     * @return array
     */
    public function getNotAllowedProperties(): array
    {
        return $this->notAllowedProperties;
    }

    /**
     * @param array $notAllowedProperties
     */
    public function setNotAllowedProperties(array $notAllowedProperties): void
    {
        $this->notAllowedProperties = $notAllowedProperties;
    }

    /**
     * Calculate encoding options
     *
     * @return integer
     */
    protected function calculateEncodeOptions(): int
    {
        return JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
    }

    /**
     *
     * @param mixed $serializedData
     *
     * @return array|string
     */
    protected function encodeNonUtf8ToUtf8(mixed $serializedData): array|string
    {
        if (is_string($serializedData)) {
            if (!mb_check_encoding($serializedData, 'UTF-8')) {
                $serializedData = [
                    static::SCALAR_IDENTIFIER_KEY => mb_convert_encoding($serializedData, 'UTF-8', '8bit'),
                    static::UTF8ENCODED_IDENTIFIER_KEY => static::VALUE_UTF8ENCODED,
                ];
            }

            return $serializedData;
        }

        $encodedKeys = [];
        $encodedData = [];
        foreach ($serializedData as $key => $value) {
            if (is_array($value)) {
                $value = $this->encodeNonUtf8ToUtf8($value);
            }

            if (!mb_check_encoding($key, 'UTF-8')) {
                $key = mb_convert_encoding($key, 'UTF-8', '8bit');
                $encodedKeys[$key] = $encodedKeys[$key] ?? 0;
                $encodedKeys[$key] |= static::KEY_UTF8ENCODED;
            }

            if (is_string($value) && !mb_check_encoding($value, 'UTF-8')) {
                $value = mb_convert_encoding($value, 'UTF-8', '8bit');
                $encodedKeys[$key] = $encodedKeys[$key] ?? 0;
                $encodedKeys[$key] |= static::VALUE_UTF8ENCODED;
            }

            $encodedData[$key] = $value;
        }

        if ($encodedKeys) {
            $encodedData[self::UTF8ENCODED_IDENTIFIER_KEY] = $encodedKeys;
        }

        return $encodedData;
    }

    /**
     * Execute post-encoding actions
     *
     * @param string $encoded
     *
     * @return string
     */
    protected function processEncodedValue(string $encoded): string
    {
        return $encoded;
    }

    /**
     * Unserialize the value from JSON
     *
     * @param string $value
     *
     * @return mixed
     */
    public function unserialize(string $value): mixed
    {
        $this->reset();
        try {
            $data = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            if ($data === null && $e->getCode() !== JSON_ERROR_NONE) {
                throw new JsonSerializerException('Invalid JSON to unserialize.' . $e->getCode() . ' ' . $e->getMessage());
            }

            if (mb_strpos($value, static::UTF8ENCODED_IDENTIFIER_KEY) !== false) {
                $data = $this->decodeNonUtf8FromUtf8($data);
            }
        }

        return $this->unserializeData($data);
    }

    /**
     *
     * @param mixed $serializedData
     *
     * @return mixed
     */
    protected function decodeNonUtf8FromUtf8(mixed $serializedData)
    {
        if (is_array($serializedData) && isset($serializedData[static::SCALAR_IDENTIFIER_KEY])) {
            return mb_convert_encoding($serializedData[static::SCALAR_IDENTIFIER_KEY], '8bit', 'UTF-8');
        }

        if (is_scalar($serializedData) || $serializedData === null) {
            return $serializedData;
        }

        $encodedKeys = [];
        if (isset($serializedData[static::UTF8ENCODED_IDENTIFIER_KEY])) {
            $encodedKeys = $serializedData[static::UTF8ENCODED_IDENTIFIER_KEY];
            unset($serializedData[static::UTF8ENCODED_IDENTIFIER_KEY]);
        }

        $decodedData = [];
        foreach ($serializedData as $key => $value) {
            if (is_array($value)) {
                $value = $this->decodeNonUtf8FromUtf8($value);
            }

            if (isset($encodedKeys[$key])) {
                $originalKey = $key;
                if ($encodedKeys[$key] & static::KEY_UTF8ENCODED) {
                    $key = mb_convert_encoding($key, '8bit', 'UTF-8');
                }
                if ($encodedKeys[$originalKey] & static::VALUE_UTF8ENCODED) {
                    $value = mb_convert_encoding($value, '8bit', 'UTF-8');
                }
            }

            $decodedData[$key] = $value;
        }

        return $decodedData;
    }

    /**
     * Parse the json decode to convert to objects again
     *
     * @param mixed $value
     *
     * @return mixed
     * @throws ReflectionException
     */
    protected function unserializeData(mixed $value)
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (isset($value[static::CLASS_IDENTIFIER_KEY])) {
            return $this->unserializeObject($value);
        }

        if (!empty($value[static::CLOSURE_IDENTIFIER_KEY])) {
            $serializerClass = $value['serializer'] ?? ClosureSerializer\SuperClosureSerializer::class;
            $serializer = $this->closureManager->getSerializer($serializerClass);
            if (!$serializer) {
                throw new JsonSerializerException('Closure serializer not provided to unserialize closure');
            }

            return $serializer->unserialize($value['value']);
        }

        return array_map([$this, __FUNCTION__], $value);
    }

    /**
     * Convert the serialized array into an object
     *
     * @param array $value
     *
     * @return mixed|ObjectId|UTCDateTime|object|SplDoublyLinkedList
     * @throws ReflectionException
     */
    protected function unserializeObject(array $value): mixed
    {
        $className = $value[static::CLASS_IDENTIFIER_KEY];
        unset($value[static::CLASS_IDENTIFIER_KEY]);

        if ($className[0] === '@') {
            $index = substr($className, 1);

            return $this->objectMapping[$index];
        }

        if (array_key_exists($className, $this->customObjectSerializerMap)) {
            $obj = $this->customObjectSerializerMap[$className]->unserialize($value);
            $this->objectMapping[$this->objectMappingIndex++] = $obj;

            return $obj;
        }

        if (!class_exists($className)) {
            throw new JsonSerializerException('Unable to find class ' . $className);
        }

        if ($className === 'DateTime' || $className === 'DateTimeImmutable') {
            $obj = $this->restoreUsingUnserialize($className, $value);
            $this->objectMapping[$this->objectMappingIndex++] = $obj;

            return $obj;
        }

        if (is_subclass_of($className, UnitEnum::class)) {
            $obj = constant("$className::{$value['name']}");
            $this->objectMapping[$this->objectMappingIndex++] = $obj;

            return $obj;
        }

        if (extension_loaded('mongodb') && $className === 'MongoDB\BSON\ObjectId') {
            $obj = new ObjectId($value['oid']);
            $this->objectMapping[$this->objectMappingIndex++] = $obj;

            return $obj;
        }

        if (extension_loaded('mongodb') && $className === 'MongoDB\BSON\UTCDateTime') {
            $obj = new UTCDateTime($value['milliseconds']);
            $this->objectMapping[$this->objectMappingIndex++] = $obj;

            return $obj;
        }

        if (!$this->isSplList($className)) {
            $ref = new ReflectionClass($className);
            $obj = $ref->newInstanceWithoutConstructor();
        } else {
            $obj = new $className();
        }

        if ($obj instanceof SplDoublyLinkedList) {
            $obj->unserialize($value['value']);
            $this->objectMapping[$this->objectMappingIndex++] = $obj;

            return $obj;
        }

        $this->objectMapping[$this->objectMappingIndex++] = $obj;
        foreach ($value as $property => $propertyValue) {
            try {
                if (!isset($ref)) {
                    throw new RuntimeException('ReflectionClass not set');
                }
                $propRef = $ref->getProperty($property);
                $propRef->setAccessible(true);
                $propRef->setValue($obj, $this->unserializeData($propertyValue));
            } catch (ReflectionException $e) {
                switch ($this->undefinedAttributeMode) {
                    case static::UNDECLARED_PROPERTY_MODE_SET:
                        $obj->$property = $this->unserializeData($propertyValue);
                        break;
                    case static::UNDECLARED_PROPERTY_MODE_IGNORE:
                        break;
                    case static::UNDECLARED_PROPERTY_MODE_EXCEPTION:
                        throw new JsonSerializerException('Undefined attribute detected during unserialization');
                }
            }
        }
        if (method_exists($obj, '__wakeup')) {
            $obj->__wakeup();
        }

        return $obj;
    }

    protected function restoreUsingUnserialize($className, $attributes)
    {
        $obj = (object)$attributes;
        $serialized = preg_replace(
            '|^O:\d+:"\w+":|',
            'O:' . strlen($className) . ':"' . $className . '":',
            serialize($obj)
        );

        return unserialize($serialized);
    }

    /**
     *
     * @param $className
     *
     * @return boolean
     */
    protected function isSplList($className): bool
    {
        return in_array($className, ['SplQueue', 'SplDoublyLinkedList', 'SplStack']);
    }

    /**
     * Set unserialization mode for undeclared class properties
     *
     * @param integer $value One of the JsonSerializer::UNDECLARED_PROPERTY_MODE_*
     *
     * @return self
     * @throws InvalidArgumentException When the value is not one of the UNDECLARED_PROPERTY_MODE_* options
     */
    public function setUnserializeUndeclaredPropertyMode(int $value): self
    {
        $availableOptions = [
            static::UNDECLARED_PROPERTY_MODE_SET,
            static::UNDECLARED_PROPERTY_MODE_IGNORE,
            static::UNDECLARED_PROPERTY_MODE_EXCEPTION
        ];
        if (!in_array($value, $availableOptions)) {
            throw new InvalidArgumentException('Invalid value.');
        }
        $this->undefinedAttributeMode = $value;

        return $this;
    }
}
