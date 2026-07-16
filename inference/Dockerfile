FROM python:3.12-slim

WORKDIR /srv
COPY pyproject.toml ./
# Runtime image installs runtime deps only — the `test` extra (pytest/httpx)
# is dev/CI tooling and must not ship in the production service image.
RUN pip install --no-cache-dir "."
COPY app ./app

EXPOSE 8000
CMD ["uvicorn", "app.main:app", "--host", "0.0.0.0", "--port", "8000"]
