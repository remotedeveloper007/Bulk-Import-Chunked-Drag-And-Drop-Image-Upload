# Product CSV Importer (with Image Upload)

Simple tool to import products from a CSV and upload images in batches. Built on Laravel with a small frontend page to keep things easy.

## What it does
- Import products from a CSV file (`sku,name,price,image`) column.
- Upserts by `sku` (new rows are created, existing rows are updated).
- Upload many images with drag‑and‑drop; the app processes variants in the background.
- Links images to products by filename (case‑insensitive). The largest variant becomes the primary image.

## Prerequisites
- PHP 8.x
- Composer
- Node.js and npm
- A database (MySQL/PostgreSQL) configured in `.env`

## Quick setup
1) Install dependencies

```bash
composer install
npm install
```

2) Environment setup

```bash
copy .env.example .env   # On Windows (PowerShell/Command Prompt)
# or: cp .env.example .env
php artisan key:generate
```

3) Configure your database
- Open `.env` and set `DB_*` values.
- Run migrations:

```bash
php artisan migrate
```

4) Start the servers

```bash
php artisan serve       # Backend (http://localhost:8000)
npm run dev             # Frontend assets (Vite)
```

5) Start the queue worker (for image processing)

```bash
php artisan queue:work
```

## Using the app
1) Open the app in your browser:
- `http://localhost:8000` shows the “Bulk CSV Import & Batch Image Upload” page.

2) Upload images (optional, recommended before CSV import):
- Drag and drop multiple image files into the upload area.
- The app uploads in chunks and processes variants (256px, 512px, 1024px).
- When done, the upload is marked “completed”.

3) Import your CSV:
- Choose your CSV and click “Import Products”.
- Required columns: `sku,name,price`
- Optional column: `image` (the original filename of the uploaded image)
- Example CSV: `samples/products_template_with_images.csv`

## How images are linked
- Matching is done by original filename (case‑insensitive).
- If the CSV uses `image` without an extension, it still tries to match the base name.
- Only uploads with status `completed` are considered.
- If an image is found, the largest variant is set as the product’s primary image.

## CSV import summary
After import, you’ll see:
- Total rows
- Imported (new) vs Updated (existing)
- Invalid rows and duplicates found in the CSV
- Images linked and images not found (when `image` column is used)

## Testing
Run the test suite:

```bash
php artisan test
```

## Troubleshooting
- CSV validation: make sure `sku`, `name`, and a positive `price` are present.
- Queue not running: image variants won’t be generated; start `php artisan queue:work`.
- Images not visible: ensure uploads completed; if needed, check storage permissions.
- Large uploads: the page adapts concurrency to keep uploads stable; retries are built in.

## Helpful paths
- CSV upload endpoint: `POST /import/products`
- Image chunk upload endpoint: `POST /upload/chunk`
- Attach image to product: `POST /products/{product}/attach-image/{upload}`
- Main page/view: `GET /` → bulk import & upload UI


## Notes
- Keep filenames simple (no spaces if possible) for easier matching.
- You can run image uploads first, then import CSV with the `image` column to auto‑link.
