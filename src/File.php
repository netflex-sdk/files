<?php

namespace Netflex\Files;

use Carbon\Carbon;

use Netflex\Query\QueryableModel;

use Netflex\Pages\Contracts\MediaUrlResolvable;
use Netflex\Query\Exceptions\NotFoundException;

use Illuminate\Http\UploadedFile;
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
 * @property-read string|null $extesion
 * @property-read string $resolution
 */
class File extends QueryableModel implements MediaUrlResolvable
{
    protected $relation = 'file';

    protected $resolvableField = 'id';

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
        return $this->img_res ?? ($this->img_width . 'x' . $this->img_height);
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
    protected function performRetrieveRequest(?int $relationId = null, $key)
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
    protected function performUpdateRequest(?int $relationId = null, $key, $attributes = [])
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
    protected function performDeleteRequest(?int $relationId = null, $key)
    {
        $this->getConnection()
            ->delete('files/file/' . $key);

        return true;
    }

    /**
     * @param UploadedFile|File|string $file
     * @param array $attributes
     * @param int|null $folder
     * @return static
     */
    public static function upload($file, $attributes = [], $folder = null)
    {
        $instance = new static;

        if (isset($attributes['folder_id']) && $folder === null) {
            $folder = $attributes['folder_id'];
        }

        if ($file instanceof File) {
            $folder = $folder ?? $file->folder_id;
            $attributes['tags'] = implode(',', $attributes['tags'] ?? $file->tags);
            $attributes['description'] = $attributes['description'] ?? $file->description;
            $attributes['title'] = $attributes['title'] ?? $file->title;
            $attributes['size'] = $attributes['size'] ?? $file->size;
            $attributes['img_width'] = $attributes['img_width'] ?? $file->img_width;
            $attributes['img_height'] = $attributes['img_height'] ?? $file->img_height;
            $attributes['img_artist'] = $attributes['img_artist'] ?? $file->img_artist;
            $attributes['img_o_date'] = $attributes['img_o_date'] ?? $file->img_o_date;
            $attributes['img_desc'] = $attributes['img_desc'] ?? $file->img_desc;
            $file = $file->url();
        }

        $folder = $folder === null ? 0 : $folder;

        /** @var Client */
        $connection = $instance->getConnection();
        $client = $connection->getGuzzleInstance();
        $baseUrl = 'files/folder/' . $folder;

        if (isset($attributes['name'])) {
            $attributes['filenamename'] = $attributes['name'];
            unset($attributes['name']);
        }

        if ($file instanceof UploadedFile) {
            $name = $attributes['filename'] ?? $file->getClientOriginalName();

            $payload = [
                [
                    'name'     => 'file',
                    'contents' => fopen($file->getFilename(), 'r'),
                    'filename' => $name,
                ]
            ];

            foreach ($attributes as $key => $value) {
                $payload[] =
                    [
                        'name'     => $key,
                        'contents' => $value
                    ];
            }

            $response = json_decode($client->post($baseUrl . '/file', [
                'multipart' => $payload
            ])->getBody(), true);

            return (new static())->newFromBuilder($response);
        }

        if (is_string($file) || (is_object($file) && method_exists($file, '__toString'))) {
            $file = (string)$file;

            if (filter_var($file, FILTER_VALIDATE_URL)) {
                $attributes['link'] = $file;

                if (!isset($attributes['filename'])) {
                    $attributes['filename'] = pathinfo($file, PATHINFO_BASENAME);
                }

                $response = $connection->post($baseUrl . '/link', $attributes, true);
                return (new static())->newFromBuilder($response);
            } else {
                $attributes['file'] = $file;

                if (!isset($attributes['filename'])) {
                    throw new InvalidArgumentException('Name is required when uploading a base64 encoded file');
                }

                $response = $connection->post($baseUrl . '/base64', $attributes, true);
                return (new static())->newFromBuilder($response);
            }
        }

        throw new InvalidArgumentException('Invalid file type');
    }
}
