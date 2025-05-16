#!/bin/bash

# Script to create a ZIP file of the Flickr Random Gallery WordPress plugin
# Excluding development files and directories

# Exit on any error
set -e

# Directory of the script (plugin root)
PLUGIN_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PLUGIN_DIR"

# Extract version from the main plugin file
VERSION=$(grep -o "Version: [0-9.]*" flickr-random-gallery.php | awk '{print $2}')

# Plugin name
PLUGIN_NAME="flickr-random-gallery"

# Create a zip file with version in the name
ZIP_FILE="../${PLUGIN_NAME}-${VERSION}.zip"

echo "Creating plugin zip file for version ${VERSION}..."

# Create the zip file, excluding unnecessary files
zip -r "$ZIP_FILE" . \
    -x "*.git*" \
    -x "*.idea/*" \
    -x "*.DS_Store" \
    -x "node_modules/*" \
    -x "create-plugin-zip.sh" \
    -x "*.zip" \
    -x "*.log" \
    -x "*.bak" \
    -x "*.tmp" \
    -x ".sass-cache/*" \
    -x "*.map" \
    -x "*.swp" 

# Check if the zip creation was successful
if [ $? -eq 0 ]; then
    # Make the path absolute
    ABSOLUTE_PATH=$(cd "$(dirname "$ZIP_FILE")" && pwd)/$(basename "$ZIP_FILE")
    echo "✅ Plugin zip file created successfully!"
    echo "📦 Location: $ABSOLUTE_PATH"
    echo "📋 Size: $(du -h "$ZIP_FILE" | cut -f1)"
else
    echo "❌ Failed to create plugin zip file."
    exit 1
fi

