#!/usr/bin/env python3
"""Build installable WP Panda test-product ZIPs using the current client SDK."""
from pathlib import Path
from zipfile import ZIP_DEFLATED, ZipFile

ROOT = Path(__file__).resolve().parents[1]
FIXTURES = Path(__file__).resolve().parent / "fixtures"
OUTPUT = Path(__file__).resolve().parent / "build"
SDK = ROOT / "client" / "wp-panda-updater.php"
PRODUCTS = (
    "wp-panda-test-alpha",
    "wp-panda-test-beta",
    "wp-panda-test-theme",
)


def build_product(slug: str) -> Path:
    source = FIXTURES / slug
    if not source.is_dir():
        raise FileNotFoundError(f"Missing fixture source: {source}")
    if not SDK.is_file():
        raise FileNotFoundError(f"Missing client SDK: {SDK}")

    archive_path = OUTPUT / f"{slug}.zip"
    with ZipFile(archive_path, "w", compression=ZIP_DEFLATED, compresslevel=9) as archive:
        for file_path in sorted(source.rglob("*")):
            if file_path.is_file():
                archive.write(file_path, (Path(slug) / file_path.relative_to(source)).as_posix())
        archive.write(SDK, (Path(slug) / "wp-panda-updater.php").as_posix())
    return archive_path


def main() -> None:
    OUTPUT.mkdir(parents=True, exist_ok=True)
    for slug in PRODUCTS:
        archive_path = build_product(slug)
        print(f"Built {archive_path.relative_to(ROOT)}")


if __name__ == "__main__":
    main()
