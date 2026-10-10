//go:build ignore

# Go backend image (Phase 1). This file is intentionally separate from the
# existing PHP Dockerfile so the current deployment can keep running in
# parallel.
#
# The leading `//go:build ignore` line is a Go build constraint that keeps the
# Go toolchain from trying to compile this *.go-named file. When building the
# image, drop that first line (or use the equivalent `Dockerfile`):
#   docker build -f Dockerfile.go -t z-coc-go .

# --- build stage ---------------------------------------------------------
FROM golang:1.26-alpine AS build
WORKDIR /src
COPY go.mod go.sum ./
RUN go mod download
COPY . .
RUN CGO_ENABLED=0 GOOS=linux go build -trimpath -ldflags="-s -w" -o /out/z-coc ./cmd/server

# --- runtime stage -------------------------------------------------------
FROM gcr.io/distroless/static-debian12
COPY --from=build /out/z-coc /z-coc
EXPOSE 8080
ENTRYPOINT ["/z-coc"]
