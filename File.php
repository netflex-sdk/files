<?php

namespace Netflex\Files;


use Closure;

use Carbon\Carbon;
use Exception;
use Netflex\Query\Builder;
use Netflex\Query\QueryableModel;

use Netflex\Pages\Contracts\MediaUrlResolvable;
use Netflex\Query\Exceptions\NotFoundException;

use Illuminate\Http\UploadedFile;
use Illuminate\Http\File as BaseFile;

use InvalidArgumentException;
use Netflex\API\Client;

/**
 * @property int $id
 * @property int|null $folder_id
 * @property string $name
 * @property string|null $path
 * @property string|null $description
 * @property string[] $tags
 * @property int $size
 * @property string $type
 * @property Carbon $created
 * @property int $userid
 * @property bool $public
 * @property int[] $related_entries
 * @property int[] $related_customers
 * @property int|null $width
 * @property int|null $height
 * @property int|null $img_width
 * @property int|null $img_height
 * @property string|null $img_res
 * @property float|null $img_lat
 * @property float|null $img_lon
 * @property string|null $img_artist
 * @property string|null $img_desc
 * @property string|null $img_alt
 * @property Carbon $img_o_date
 * @property string $foldercode
 * @property-read string|null $extension
 * @property-read string $resolution
 */
class File extends QueryableModel implements MediaUrlResolvable
{
    protected $relation = 'file';

    protected $resolvableField = 'id';

    protected $fillable = [
        'name',
        'folder_id',
        'description',
        'tags',
        'related_entries',
        'related_customers',
        'img_lat',
        'img_lon',
        'img_artist',
        'img_desc',
        'img_alt',
        'img_o_date',
    ];

    protected $casts = [
        'userid' => 'int',
        'public' => 'bool',
    ];

    /**
     * The name of the "created at" column.
     *
     * @var string
     */
    const CREATED_AT = 'created';

    /**
     * The attributes that should be mutated to dates.
     *
     * @deprecated Use the "casts" property
     *
     * @var array
     */
    protected $dates = [
        'created',
        'img_o_date',
    ];

    protected $resolvedDimensions = null;

    /**
     * Retrieve the model for a bound value.
     *
     * @param  mixed  $rawValue
     * @param  string|null $field
     * @return \Illuminate\Database\Eloquent\Model|null
     * @throws NotFoundException
     */
    public function resolveRouteBinding($rawValue, $field = null)
    {
        $field = $field ?? $this->getResolvableField();
        $query = static::where($field, $rawValue);

        /** @var static */
        if ($model = $query->first()) {
            return $model;
        }

        $e = new NotFoundException;
        $e->setModel(static::class, [$rawValue]);

        throw $e;
    }

    public function getExtensionAttribute()
    {
        if ($extension = pathinfo($this->path, PATHINFO_EXTENSION)) {
            return '.' . $extension;
        }
    }

    protected function resolveDimensions()
    {
        $this->resolvedDimensions = $this->resolvedDimensions ?? getimagesize($this->url(null));
        return $this->resolvedDimensions;
    }

    public function getWidthAttribute()
    {
        return $this->img_width;
    }

    public function getHeightAttribute()
    {
        return $this->img_height;
    }

    public function setWidthAttribute($width)
    {
        return $this->img_width = $width;
    }

    public function setHeightAttribute($height)
    {
        return $this->img_height = $height;
    }

    public function getImgWidthAttribute($img_width)
    {
        if ($img_width === null) {
            @list($img_width) = $this->resolveDimensions();
            $this->img_width = $img_width;
            $this->save();
        }

        return $img_width;
    }

    public function getImgHeightAttribute($img_height)
    {
        if ($img_height === null) {
            @list($img_height) = $this->resolveDimensions();
            $this->img_height = $img_height;
            $this->save();
        }

        return $img_height;
    }

    public function getResolutionAttribute()
    {
        return $this->img_width . 'x' . $this->img_height;
    }


    /** @return string */
    public function getPathAttribute()
    {
        return $this->attributes['path'];
    }

    /**
     * @param string|null $preset 
     * @return string|null 
     */
    public function url($preset = null)
    {
        if ($path = $this->getPathAttribute()) {
            if ($preset) {
                return media_url($this->getPathAttribute(), $preset);
            }

            return cdn_url($path);
        }
    }

    public function getRelatedEntriesAttribute($related_entries)
    {
        return array_map(fn ($entry) => intval($entry), array_values(array_filter(explode(',', $related_entries ?? ''))));
    }

    public function setRelatedEntriesAttribute($related_entries = [])
    {
        if (is_array($related_entries)) {
            $related_entries = implode(',', $related_entries);
        }

        $this->attributes['related_entries'] = $related_entries;
    }

    public function getRelatedCustomersAttribute($related_customers)
    {
        return array_map(fn ($customer) => intval($customer), array_values(array_filter(explode(',', $related_customers ?? ''))));
    }

    public function setRelatedCustomersAttribute($related_customers = [])
    {
        if (is_array($related_customers)) {
            $related_customers = implode(',', $related_customers);
        }

        $this->attributes['related_customers'] = $related_customers;
    }

    public function setTagsAttribute($tags = [])
    {
        if (is_string($tags)) {
            $tags = array_values(array_filter(explode(',', $tags))) ?: [];
        }

        return parent::setTagsAttribute($tags);
    }

    /**
     * Retrieves a record by key
     *
     * @param int|null $relationId
     * @param mixed $key
     * @return array|null
     */
    protected function performRetrieveRequest(?int $relationId = null, mixed $key = null)
    {
        return $this->getConnection()->get('files/file/' . $key, true);
    }

    /**
     * Inserts a new record, and returns its id
     *
     * @property ?int $relationId
     * @property array $attributes
     * @return mixed
     */
    protected function performInsertRequest(?int $relationId = null, array $attributes = [])
    {
        return $this->getConnection()
            ->post('files/file/', $attributes, true);
    }

    /**
     * Perform a model insert operation.
     *
     * @return bool
     */
    protected function performInsert()
    {
        if ($success = parent::performInsert()) {
            $this->getResolutionAttribute();
            return $success;
        }

        return false;
    }


    /**
     * Updates a record
     *
     * @param int|null $relationId
     * @param mixed $key
     * @param array $attributes
     * @return void
     */
    protected function performUpdateRequest(?int $relationId = null, mixed $key = null, $attributes = [])
    {
        $this->getConnection()
            ->put('files/file/' . $key, $attributes);
    }

    /**
     * Deletes a record
     *
     * @param int|null $relationId
     * @param mixed $key
     * @return bool
     */
    protected function performDeleteRequest(?int $relationId = null, mixed $key = null)
    {
        $this->getConnection()
            ->delete('files/file/' . $key);

        return true;
    }

    /**
     * @param string|null $newName
     * @param array $newAttributes
     * @param int|null $newFolder
     * @return static
     */
    public function copy($newName = null, $newAttributes = [], $newFolder = null)
    {
        $attributes = $newAttributes;
        $attributes['folder_id'] = $newFolder ?? $this->folder_id;
        $attributes['name'] = $newName ?? $this->name;

        foreach ($this->attributes as $key => $value) {
            if (!in_array($key, ['link', 'id', 'path']) && !in_array($key, array_keys($attributes))) {
                $attributes[$key] = $value;
            }
        }

        return static::upload($this->url(), $attributes);
    }

    /**
     * @param UploadedFile|BaseFile|File|string $file
     * @param array $attributes
     * @param int|null $folder
     * @return static
     */
    public static function upload($file, $attributes = [], $folder = 10000)
    {
        $instance = new static;

        if (is_int($attributes)) {
            $folder = $attributes;
            $attributes = [];
        }

        $attributes['folder_id'] = $attributes['folder_id'] ?? $folder;
        $folder = $attributes['folder_id'];

        if ($file instanceof File) {
            foreach ($instance->fillable as $fillable) {
                $attributes['fillable'] = $attributes[$fillable] ?? $file->{$fillable} ?? null;
            }
            $file = $file->url();
        }

        foreach (array_keys($attributes) as $key) {
            if (is_array($attributes[$key])) {
                $attributes[$key] = implode(',', $attributes[$key]);
            }
        }

        $folder = $attributes['folder_id'];

        /** @var Client */
        $connection = $instance->getConnection();
        $client = $connection->getGuzzleInstance();
        $baseUrl = 'files/folder/' . $folder;

        if (isset($attributes['name'])) {
            $attributes['filename'] = $attributes['name'];
            unset($attributes['name']);
        }

        if (($file instanceof UploadedFile) || ($file instanceof BaseFile)) {
            $name = $attributes['filename'] ?? $file->getClientOriginalName();

            $payload = [
                [
                    'name'     => 'file',
                    'contents' => fopen($file->getRealPath(), 'r'),
                    'filename' => $name,
                ]
            ];

            foreach ($attributes as $key => $value) {
                $payload[] = ['name' => $key, 'contents' => $value];
            }

            $response = json_decode($client->post($baseUrl . '/file', [
                'multipart' => $payload
            ])->getBody());

            return static::find($response->id);
        }

        if (is_string($file) || (is_object($file) && method_exists($file, '__toString'))) {
            $file = (string)$file;

            if (filter_var($file, FILTER_VALIDATE_URL)) {
                $attributes['link'] = $file;

                if (!isset($attributes['filename'])) {
                    $attributes['filename'] = pathinfo($file, PATHINFO_BASENAME);
                }

                $response = $connection->post($baseUrl . '/link', $attributes);
                return static::find($response->id);
            } else {
                $attributes['file'] = $file;

                if (!isset($attributes['filename'])) {
                    throw new InvalidArgumentException('Name is required when uploading a base64 encoded file');
                }

                $response = $connection->post($baseUrl . '/base64', $attributes);
                return static::find($response->id);
            }
        }

        throw new InvalidArgumentException('Invalid file type');
    }

    /**
     * @param Closure|Builder|string $query
     */
    public static function tags($query = '*')
    {
        $instance = new static;
        $connection = $instance->getConnection();

        if ($query instanceof Builder) {
            $query = $query->getQuery(true);
        }

        if (is_callable($query)) {
            $builder = new Builder();
            $query($builder);
            $query = $builder->getQuery(true);
        }

        $response = $connection->post('search/raw', [
            'body' => [
                'query' => [
                    'query_string' => [
                        'query' => $query
                    ]
                ],
                'aggs' => [
                    'keywords' => [
                        'terms' => [
                            'field' => 'tags',
                            'size' => Builder::MAX_QUERY_SIZE
                        ]
                    ]
                ]
            ],
            'index' => $instance->getRelation(),
            'size' => 0
        ]);

        $tags = [];
        foreach ($response->aggregations->keywords->buckets as $bucket) {
            foreach (explode(',', $bucket->key) as $tag) {
                $tags[$tag] = $tags[$tag] ?? 0;
                $tags[$tag] += $bucket->doc_count;
            }
        }

        return array_filter($tags);
    }
}
