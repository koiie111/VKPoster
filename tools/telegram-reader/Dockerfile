# Native runtime validated against reader/HTTP/session compatibility tests (ADR 0021).
FROM python:3.13.16-alpine3.24@sha256:2d9aefe2fef018a7eb2c13064c89c71929800fd2e5dccdbf52ea5da5bb8d929a
ENV PYTHONUNBUFFERED=1 PYTHONDONTWRITEBYTECODE=1
WORKDIR /spike
RUN apk upgrade --no-cache
COPY requirements.txt .
RUN pip install --no-cache-dir --require-hashes -r requirements.txt \
    && python -m pip uninstall --yes pip \
    && rm -rf /usr/local/lib/python3.13/ensurepip \
    && adduser -D -u 1000 reader \
    && mkdir /state && chown reader:reader /state
COPY reader reader
COPY tests tests
USER reader
ENTRYPOINT ["python", "-m", "reader.cli"]
CMD ["--help"]
