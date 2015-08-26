#!/bin/sh
echo Building Docker image.
docker build -t php-mvc-nginx .
echo Running Docker container...
docker run -v `pwd`:/opt -p 80:80 php-mvc-nginx
