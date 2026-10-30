#!/usr/bin/env python3
"""
Generate data/postal-centroids.csv from the Census ZCTA Gazetteer file.

Downloads the 2020 ZCTA5 Gazetteer directly from the Census Bureau and
converts it to a postal_code,lat,lng CSV for bundling with the plugin.
The activator imports it into the postal centroid table.

Usage:
    python utils/generate-centroids.py

Output:
    data/postal-centroids.csv  (~1 MB, ~33k rows)

The generated file is committed to git and shipped in the plugin zip as
the bundled fallback dataset. Run this script whenever a new Census ZCTA
file is released (every decennial census, ~every 10 years).
"""

import os
import sys
import io
import zipfile
import urllib.request

CENSUS_URL = (
    'https://www2.census.gov/geo/docs/maps-data/data/gazetteer/'
    '2020_Gazetteer/2020_Gaz_zcta_national.zip'
)

SCRIPT_DIR  = os.path.dirname(os.path.abspath(__file__))
PLUGIN_ROOT = os.path.dirname(SCRIPT_DIR)
OUTPUT_PATH = os.path.join(PLUGIN_ROOT, 'data', 'postal-centroids.csv')


def download_tsv(url: str) -> str:
    print(f'Downloading {url} ...')
    with urllib.request.urlopen(url, timeout=60) as resp:
        zip_bytes = resp.read()
    print(f'  Downloaded {len(zip_bytes):,} bytes.')

    with zipfile.ZipFile(io.BytesIO(zip_bytes)) as zf:
        for name in zf.namelist():
            if name.endswith('.txt'):
                print(f'  Extracting {name} ...')
                return zf.read(name).decode('utf-8', errors='replace')

    raise RuntimeError('No .txt file found inside the Census ZIP.')


def parse_tsv(content: str):
    lines = content.splitlines()
    if not lines:
        return []

    headers = [h.strip().lower() for h in lines[0].split('\t')]

    def find_col(candidates):
        for name in candidates:
            if name in headers:
                return headers.index(name)
        return None

    zip_col = find_col(['geoid', 'postal_code', 'zcta', 'zip'])
    lat_col = find_col(['intptlat', 'lat', 'latitude'])
    lng_col = find_col(['intptlong', 'lng', 'longitude'])

    if None in (zip_col, lat_col, lng_col):
        raise RuntimeError(
            f'Could not identify required columns. Headers: {headers}'
        )

    rows = []
    for line in lines[1:]:
        cols = line.split('\t')
        if len(cols) <= max(zip_col, lat_col, lng_col):
            continue
        postal_code = cols[zip_col].strip().split('-')[0]  # strip ZIP+4
        try:
            lat = float(cols[lat_col].strip())
            lng = float(cols[lng_col].strip())
        except ValueError:
            continue
        if not postal_code or lat == 0.0 and lng == 0.0:
            continue
        rows.append((postal_code, lat, lng))

    return rows


def write_csv(rows, output_path: str):
    import csv
    os.makedirs(os.path.dirname(output_path), exist_ok=True)

    with open(output_path, 'w', encoding='utf-8', newline='') as f:
        writer = csv.writer(f, lineterminator='\n')
        writer.writerow(['postal_code', 'lat', 'lng'])
        for postal_code, lat, lng in rows:
            writer.writerow([postal_code, f'{lat:.7f}', f'{lng:.7f}'])

    print(f'Written {len(rows):,} rows to {output_path}')


def main():
    try:
        tsv = download_tsv(CENSUS_URL)
        rows = parse_tsv(tsv)
        print(f'Parsed {len(rows):,} ZIP centroids.')
        write_csv(rows, OUTPUT_PATH)
    except Exception as exc:
        print(f'Error: {exc}', file=sys.stderr)
        sys.exit(1)


if __name__ == '__main__':
    main()
