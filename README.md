# Netflex files library

This package simplifies working with the Netflex Files API.

## Installation

```bash
composer require netflex/files
```

## Basic usage

The File class provides a fluent query builder to search for files.

```php
<?php

use Netflex\Files\File;

$files = File::where('type', 'png')
    ->where('name', 'like', 'Hello*')
    ->where('created', '>=', '2021-11-01')
    ->all();
```

You can also retrieve a list of tags:

```php
<?php

use Netflex\Files\File;
use Netflex\Query\Builder;

$tags = File::tags();

// Or with a query callback:

$tags = File::tags(function ($query) {
    return $query->where('related_customers', [10000, 10010]);
})

// Or with a query builder:
$query = new Builder;
$tags = File::tags($query->where('type', ['jpg', 'png']));
```

Example, retrieving images tagged 'netflex':

```php
<?php

use Netflex\Files\File;

$taggedImages = File::where('tags', 'netflex')->all();
```

## Uploading new files

Netflex Files supports multiple methods for uploading a new file.
The most performand is to use an uploaded file directly, as this will get streamed directly to the CDN. This is the fastest method.

```php
<?php

// $request is either a Form Request or an instance of Illuminate\Http\Request
$file = $request->file('uploaded-file');

$uploadedFile = File::store($file); // Uploaded to folder '0'.
```

## Duplicating a file

```php
<?php

use Netflex\Files\File;

$file = File::find(10000)->copy('new-name.png');
```