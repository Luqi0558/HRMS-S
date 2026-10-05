FROM nginx:1.27-alpine

ARG BACKEND_DIR=orbit-hrms-api
COPY docker/nginx/api.conf /etc/nginx/conf.d/default.conf
COPY ${BACKEND_DIR}/public /var/www/html/public

EXPOSE 80
