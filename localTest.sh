#!/usr/bin/env bash

set -euo pipefail

mkdir -p "$HOME"/.composer/docker-cache

# Loop through PHP versions to build Docker images and run Composer commands
for version in {83..85}; do
  # Build Docker image for the current PHP version
  IMAGE_NAME="php-xprof-trace-composer-$version"
  docker build -t "$IMAGE_NAME" -f "docker/Dockerfile$version" .
  if [ $? -ne 0 ]; then
    echo "Docker build failed for PHP version $version"
    exit 1
  fi

  # Define Docker run command
  DOCKER_CMD="docker run -it --rm -v $(pwd):/var/www -v $HOME/.composer/docker-cache:/root/.composer $IMAGE_NAME"
  $DOCKER_CMD composer --version

  # Remove existing composer.lock, display Composer version, and run Composer install and update for PHP 8.3
  if [ "$version" -eq 83 ]; then
    rm -f composer.lock && \
    $DOCKER_CMD composer install && \
    $DOCKER_CMD composer update --with-all-dependencies && \
    $DOCKER_CMD composer require --dev --with-all-dependencies "rector/rector":">=1.0.2"

    # Check for errors immediately after Composer commands
    if [ $? -ne 0 ]; then
        echo "Composer install failed for PHP $version"
        exit 1
    fi
  fi

  $DOCKER_CMD composer tests

  # If any of the above commands fail, the script will stop and exit with a non-zero status code.
  if [ $? -ne 0 ]; then
    echo "Tests failed for PHP $version"
    exit 1
  fi
done

echo "All tests passed successfully!"
