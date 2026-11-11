FROM php:8.3-apache

RUN docker-php-ext-install mysqli

COPY admin/ /var/www/html/admin/
COPY applicant/ /var/www/html/applicant/
COPY assets/ /var/www/html/assets/
COPY auth/ /var/www/html/auth/
COPY config/ /var/www/html/config/
COPY creator/ /var/www/html/creator/
COPY includes/ /var/www/html/includes/
COPY recruiter/ /var/www/html/recruiter/
COPY *.php /var/www/html/
COPY railway-entrypoint.sh /usr/local/bin/railway-entrypoint

RUN mkdir -p /opt/seed-uploads \
    && cp -a /var/www/html/assets/uploads/. /opt/seed-uploads/ \
    && chown -R www-data:www-data /var/www/html/assets/uploads \
    && chmod +x /usr/local/bin/railway-entrypoint

EXPOSE 8080

CMD ["/usr/local/bin/railway-entrypoint"]
